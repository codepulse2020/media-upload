<?php

use CodePulse\MediaUpload\Contracts\Stage;
use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse\MediaUpload\Facades\MediaUpload;
use CodePulse\MediaUpload\Tests\Fixtures;
use CodePulse\MediaUpload\Transformers\Watermark;
use CodePulse\MediaUpload\UploadContext;
use Illuminate\Support\Facades\Storage;

function pixel(string $bytes, int $x, int $y): array
{
    $image = imagecreatefromstring($bytes);
    $rgb = imagecolorat($image, $x, $y);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

it('places an image watermark and still strips metadata', function () {
    $stored = MediaUpload::profile('image')
        ->transform(new Watermark(path: Fixtures::watermarkPng(), position: 'bottom-right', scale: 0.25, margin: 0, opacity: 100))
        ->store(Fixtures::upload(Fixtures::jpegWithExif(), 'photo.jpg'));

    $bytes = Storage::disk('local')->get($stored->path);

    // Image is 200x100: bottom-right corner is now white, top-left stays red.
    [$r, $g, $b] = pixel($bytes, 195, 95);
    expect($g)->toBeGreaterThan(200)->and($b)->toBeGreaterThan(200);

    [$r, $g, $b] = pixel($bytes, 5, 5);
    expect($r)->toBeGreaterThan(180)->and($g)->toBeLessThan(80);

    expect($bytes)->not->toContain('SECRET-GPS-LOCATION');
});

it('tiles an image watermark', function () {
    $stored = MediaUpload::profile('image')
        ->transform(new Watermark(path: Fixtures::watermarkPng(), tile: true, scale: 0.1, margin: 5, opacity: 100))
        ->store(Fixtures::upload(Fixtures::jpeg(200, 100, 'black'), 'photo.jpg'));

    [$r] = pixel(Storage::disk('local')->get($stored->path), 10, 10);
    expect($r)->toBeGreaterThan(200);
});

it('places a text watermark', function () {
    $plain = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(400, 200, 'black'), 'a.jpg'));
    $marked = MediaUpload::profile('image')
        ->transform(new Watermark(text: 'ACME', position: 'center', opacity: 100))
        ->store(Fixtures::upload(Fixtures::jpeg(400, 200, 'black'), 'a.jpg'));

    expect($marked->sha256)->not->toBe($plain->sha256);
});

it('configures watermarks per profile', function () {
    config()->set('media-upload.profiles.product', [
        'mimes' => ['image/jpeg'],
        'max_size' => 1024,
        'hooks' => [
            'transform' => [[Watermark::class, ['path' => Fixtures::watermarkPng(), 'margin' => 0, 'opacity' => 100, 'scale' => 0.25]]],
        ],
    ]);

    $stored = MediaUpload::profile('product')->store(Fixtures::upload(Fixtures::jpeg(), 'p.jpg'));

    [, $g] = pixel(Storage::disk('local')->get($stored->path), 195, 95);
    expect($g)->toBeGreaterThan(200);
});

it('lets a validated hook reject the upload before anything is written', function () {
    $quota = new class implements Stage {
        public function handle(UploadContext $ctx, Closure $next): UploadContext
        {
            if ($ctx->context['used'] >= 10) {
                throw RejectedUploadException::byHook('Storage quota exceeded.');
            }

            return $next($ctx);
        }
    };

    try {
        MediaUpload::profile('image')->hook('validated', $quota)->withContext(['used' => 10])
            ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
        $this->fail('Expected rejection.');
    } catch (RejectedUploadException $e) {
        expect($e->reason)->toBe(RejectedUploadException::REJECTED_BY_HOOK)
            ->and($e->getMessage())->toBe('Storage quota exceeded.');
    }

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('treats a hook that does not call $next as a rejection', function (string $point) {
    try {
        MediaUpload::profile('image')
            ->hook($point, fn (UploadContext $ctx, Closure $next) => $ctx)
            ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
        $this->fail('Expected rejection.');
    } catch (RejectedUploadException $e) {
        expect($e->reason)->toBe(RejectedUploadException::HOOK_HALTED);
    }

    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['validated', 'transform', 'before_store', 'after_store']);

it('lets before_store hooks choose directory, file name and meta', function () {
    $stored = MediaUpload::profile('image')
        ->hook('before_store', function (UploadContext $ctx, Closure $next) {
            $ctx->directory = 'shops/'.$ctx->context['shop_id'];
            $ctx->filename = 'cover';
            $ctx->meta['shop_id'] = $ctx->context['shop_id'];

            return $next($ctx);
        })
        ->withContext(['shop_id' => 42])
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($stored->path)->toBe('shops/42/cover.jpg')
        ->and($stored->meta)->toBe(['shop_id' => 42]);
});

it('does not let hooks escape the directory or pick an extension', function (string $directory, string $filename) {
    MediaUpload::profile('image')
        ->hook('before_store', function (UploadContext $ctx, Closure $next) use ($directory, $filename) {
            $ctx->directory = $directory;
            $ctx->filename = $filename;

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
})->with([
    ['../../public', 'x'],
    ['uploads', 'shell.php'],
    ['up loads', 'x'],
])->throws(InvalidConfigException::class);

it('deletes the stored file when an after_store hook fails', function () {
    try {
        MediaUpload::profile('image')
            ->hook('after_store', function (UploadContext $ctx, Closure $next) {
                expect(Storage::disk('local')->exists($ctx->stored()->path))->toBeTrue();

                throw new RuntimeException('CDN down');
            })
            ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('CDN down');
    }

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('runs globally registered hooks for every profile', function () {
    MediaUpload::extend('before_store', function (UploadContext $ctx, Closure $next) {
        $ctx->meta['tenant'] = 'acme';

        return $next($ctx);
    });

    expect(MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'))->meta)->toBe(['tenant' => 'acme'])
        ->and(MediaUpload::profile('document')->store(Fixtures::upload(Fixtures::cleanPdf(), 'a.pdf'))->meta)->toBe(['tenant' => 'acme']);
});

it('only allows image transformers at the transform point', function () {
    MediaUpload::profile('image')
        ->hook('validated', new Watermark(text: 'x'))
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
})->throws(InvalidConfigException::class);

it('skips transform hooks for non-images', function () {
    $stored = MediaUpload::profile('document')
        ->transform(new Watermark(text: 'x'))
        ->store(Fixtures::upload(Fixtures::cleanPdf(), 'a.pdf'));

    expect($stored->mime)->toBe('application/pdf');
});

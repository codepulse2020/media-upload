<?php

use CodePulse\MediaUpload\Contracts\VirusScanner;
use CodePulse\MediaUpload\Events\UploadRejected;
use CodePulse\MediaUpload\Events\UploadStored;
use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse\MediaUpload\Exceptions\ScannerUnavailableException;
use CodePulse\MediaUpload\Facades\MediaUpload;
use CodePulse\MediaUpload\Tests\Fixtures;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

function rejectedReason(callable $callback): ?string
{
    try {
        $callback();
    } catch (RejectedUploadException $e) {
        return $e->reason;
    }

    return null;
}

function allStoredFiles(): array
{
    return Storage::disk('local')->allFiles();
}

it('stores a valid image under an unpredictable name on the private disk', function () {
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'Holiday photo.jpg', 'image/jpeg'));

    expect($stored->disk)->toBe('local')
        ->and($stored->path)->toMatch('#^uploads/image/\d{4}/\d{2}/[0-9a-z]{26}\.jpg$#')
        ->and($stored->mime)->toBe('image/jpeg')
        ->and($stored->originalName)->toBe('Holiday photo.jpg')
        ->and($stored->width)->toBe(200)
        ->and($stored->sha256)->toBe(hash('sha256', Storage::disk('local')->get($stored->path)));
});

it('rejects PHP disguised as an image', function () {
    $reason = rejectedReason(fn () => MediaUpload::store(
        Fixtures::upload('<?php echo shell_exec($_GET["c"]);', 'photo.jpg', 'image/jpeg')
    ));

    expect($reason)->toBe(RejectedUploadException::MIME_NOT_ALLOWED)
        ->and(allStoredFiles())->toBe([]);
});

it('rejects dangerous double extensions', function (string $name) {
    expect(rejectedReason(fn () => MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), $name))))
        ->toBe(RejectedUploadException::BLOCKED_EXTENSION);
})->with(['shell.php.jpg', 'x.PHTML.jpg', 'a.phar.png', "evil.php\0.jpg"]);

it('rejects an extension that does not match the content', function () {
    expect(rejectedReason(fn () => MediaUpload::store(Fixtures::upload(Fixtures::png(), 'image.jpg'))))
        ->toBe(RejectedUploadException::EXTENSION_MISMATCH);
});

it('strips EXIF metadata', function () {
    $source = Fixtures::jpegWithExif('SECRET-GPS-LOCATION');
    expect($source)->toContain('SECRET-GPS-LOCATION');

    $stored = MediaUpload::store(Fixtures::upload($source, 'exif.jpg'));
    $bytes = Storage::disk('local')->get($stored->path);

    expect($bytes)->not->toContain('SECRET-GPS-LOCATION')
        ->and($bytes)->not->toContain('EvilCam');
});

it('removes payloads from polyglot images', function (string $contents, string $name) {
    $stored = MediaUpload::store(Fixtures::upload($contents, $name));

    expect(Storage::disk('local')->get($stored->path))->not->toContain('<?php');
})->with([
    'gif + php trailer' => [fn () => Fixtures::gifPolyglot(), 'cat.gif'],
    'php in jpeg comment' => [fn () => Fixtures::jpegWithPhpComment(), 'cat.jpg'],
]);

it('refuses decompression bombs before decoding', function () {
    expect(rejectedReason(fn () => MediaUpload::store(Fixtures::upload(Fixtures::pngBomb(), 'bomb.png'))))
        ->toBe(RejectedUploadException::IMAGE_TOO_LARGE);
});

it('refuses files over the size limit', function () {
    config()->set('media-upload.profiles.image.max_size', 1);

    expect(rejectedReason(fn () => MediaUpload::store(Fixtures::upload(Fixtures::jpeg(800, 800), 'big.jpg'))))
        ->toBe(RejectedUploadException::TOO_LARGE);
});

it('never uses the client file name for storage', function () {
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), '../../../<script>evil</script>.jpg'));

    expect($stored->path)->toStartWith('uploads/image/')
        ->and($stored->path)->not->toContain('..')
        ->and($stored->originalName)->not->toContain('<')
        ->and($stored->originalName)->not->toContain('/');
});

it('applies profile resizing and output format', function () {
    $stored = MediaUpload::profile('avatar')->store(Fixtures::upload(Fixtures::jpeg(1000, 600), 'me.jpg'));

    expect($stored->extension)->toBe('webp')
        ->and($stored->mime)->toBe('image/webp')
        ->and([$stored->width, $stored->height])->toBe([512, 512])
        ->and((new finfo(FILEINFO_MIME_TYPE))->buffer(Storage::disk('local')->get($stored->path)))->toBe('image/webp');
});

it('rejects SVG unless explicitly enabled', function () {
    config()->set('media-upload.profiles.vector', ['mimes' => ['image/svg+xml'], 'max_size' => 100]);

    expect(rejectedReason(fn () => MediaUpload::profile('vector')->store(Fixtures::upload(Fixtures::svgWithScript(), 'logo.svg'))))
        ->toBe(RejectedUploadException::BLOCKED_EXTENSION);
});

it('sanitises SVG when enabled', function () {
    config()->set('media-upload.svg.enabled', true);
    config()->set('media-upload.profiles.vector', ['mimes' => ['image/svg+xml'], 'max_size' => 100, 'disposition' => 'attachment']);

    $stored = MediaUpload::profile('vector')->store(Fixtures::upload(Fixtures::svgWithScript(), 'logo.svg'));
    $svg = Storage::disk('local')->get($stored->path);

    expect($svg)->not->toContain('<script')
        ->and($svg)->not->toContain('onload')
        ->and($svg)->toContain('<rect');
});

it('rejects PDFs with active content and stores clean ones', function () {
    expect(rejectedReason(fn () => MediaUpload::profile('document')->store(Fixtures::upload(Fixtures::pdfWithJavascript(), 'invoice.pdf'))))
        ->toBe(RejectedUploadException::UNSAFE_CONTENT);

    $stored = MediaUpload::profile('document')->store(Fixtures::upload(Fixtures::cleanPdf(), 'invoice.pdf'));

    expect($stored->mime)->toBe('application/pdf')->and($stored->extension)->toBe('pdf');
});

it('refuses public disks', function () {
    config()->set('filesystems.disks.public', ['driver' => 'local', 'root' => public_path('storage'), 'visibility' => 'public']);
    config()->set('media-upload.disk', 'public');

    MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
})->throws(InvalidConfigException::class);

it('dispatches events for stored and rejected uploads', function () {
    Event::fake([UploadStored::class, UploadRejected::class]);

    MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'ok.jpg'));
    rejectedReason(fn () => MediaUpload::store(Fixtures::upload('<?php', 'bad.jpg')));

    Event::assertDispatched(UploadStored::class);
    Event::assertDispatched(UploadRejected::class, fn ($e) => $e->reason === RejectedUploadException::MIME_NOT_ALLOWED);
});

it('rejects infected files and fails closed when the scanner is down', function () {
    app()->instance(VirusScanner::class, new class implements VirusScanner {
        public function scan($stream): ?string
        {
            return 'Eicar-Test-Signature';
        }
    });

    expect(rejectedReason(fn () => MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'))))
        ->toBe(RejectedUploadException::VIRUS_DETECTED);

    app()->instance(VirusScanner::class, new class implements VirusScanner {
        public function scan($stream): ?string
        {
            throw new ScannerUnavailableException('down');
        }
    });

    expect(rejectedReason(fn () => MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'))))
        ->toBe(RejectedUploadException::SCAN_UNAVAILABLE)
        ->and(allStoredFiles())->toBe([]);
});

<?php

use CodePulse2020\MediaUpload\Contracts\VirusScanner;
use CodePulse2020\MediaUpload\Events\UploadRejected;
use CodePulse2020\MediaUpload\Events\UploadStored;
use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Exceptions\NonSerializableHookException;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse2020\MediaUpload\Exceptions\ScanLimitExceededException;
use CodePulse2020\MediaUpload\Exceptions\ScannerUnavailableException;
use CodePulse2020\MediaUpload\Facades\MediaUpload;
use CodePulse2020\MediaUpload\Jobs\ProcessQuarantinedUpload;
use CodePulse2020\MediaUpload\MediaUploader;
use CodePulse2020\MediaUpload\Scanning\ClamAvScanner;
use CodePulse2020\MediaUpload\Support\ServePolicy;
use CodePulse2020\MediaUpload\Tests\Fixtures;
use CodePulse2020\MediaUpload\Tests\QueueJobStub;
use CodePulse2020\MediaUpload\UploadContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function reasonOf(callable $callback): ?string
{
    try {
        $callback();
    } catch (RejectedUploadException $e) {
        return $e->reason;
    }

    return null;
}

function pushedJob(): ProcessQuarantinedUpload
{
    $job = null;
    Queue::assertPushed(ProcessQuarantinedUpload::class, function ($pushed) use (&$job) {
        $job = $pushed;

        return true;
    });

    return unserialize(serialize($job));
}

/* ---------------------------------------------------------------- storage */

it('deduplicates identical content with the hash generator', function () {
    config()->set('media-upload.filename.generator', 'hash');

    $first = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $second = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'b.jpg'));

    expect($second->path)->toBe($first->path)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('keeps shared deduplicated files when an after_store hook fails', function () {
    config()->set('media-upload.filename.generator', 'hash');
    $first = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect(fn () => MediaUpload::profile('image')
        ->hook('after_store', fn () => throw new RuntimeException('boom'))
        ->store(Fixtures::upload(Fixtures::jpeg(), 'b.jpg')))->toThrow(RuntimeException::class);

    expect(Storage::disk('local')->exists($first->path))->toBeTrue();
});

it('refuses to overwrite a hook chosen name', function () {
    config()->set('media-upload.managed_directories', ['covers']);
    $named = fn () => MediaUpload::profile('image')
        ->hook('before_store', function (UploadContext $ctx, Closure $next) {
            $ctx->directory = 'covers';
            $ctx->filename = 'shop-1';

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(200, 100, 'red'), 'a.jpg'));

    $first = $named();
    $before = Storage::disk('local')->get($first->path);

    expect(reasonOf($named))->toBe(RejectedUploadException::PATH_CONFLICT)
        ->and(Storage::disk('local')->get($first->path))->toBe($before);
});

it('refuses S3 disks with public ACLs', function (string $key) {
    config()->set('filesystems.disks.s3pub', ['driver' => 's3', 'bucket' => 'b', 'region' => 'us-east-1', 'options' => $key === 'ACL'
        ? ['ACL' => 'public-read']
        : ['params' => ['ACL' => 'public-read']]]);
    config()->set('media-upload.disk', 's3pub');

    MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
})->with(['ACL', 'params.ACL'])->throws(InvalidConfigException::class);

it('refuses scoped disks backed by a public disk', function () {
    config()->set('filesystems.disks.public', ['driver' => 'local', 'root' => storage_path('app/public'), 'visibility' => 'public']);
    config()->set('filesystems.disks.tenant', ['driver' => 'scoped', 'disk' => 'public', 'prefix' => 'tenant-1']);
    config()->set('media-upload.disk', 'tenant');

    MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
})->throws(InvalidConfigException::class);

it('refuses scoped disks whose backing root is inside public/', function () {
    config()->set('filesystems.disks.web', ['driver' => 'local', 'root' => public_path()]);
    config()->set('filesystems.disks.tenant', ['driver' => 'scoped', 'disk' => 'web', 'prefix' => 'uploads']);
    config()->set('media-upload.queue.quarantine_disk', 'tenant');

    MediaUpload::storeAsync(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
})->throws(InvalidConfigException::class);

/* ------------------------------------------------------------- pipeline */

it('keeps meta written by after_store hooks', function () {
    $stored = MediaUpload::profile('image')
        ->hook('after_store', function (UploadContext $ctx, Closure $next) {
            $ctx->meta['thumb'] = 'thumbs/'.basename($ctx->stored()->path);

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($stored->meta)->toHaveKey('thumb');
});

it('detects hooks that change the detected type', function (string $point) {
    expect(reasonOf(fn () => MediaUpload::profile('image')
        ->hook($point, function (UploadContext $ctx, Closure $next) {
            $ctx->setMime('text/plain');

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::gifPolyglot(), 'a.gif'))))->toBe(RejectedUploadException::HOOK_TAMPERED)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['validated', 'transform']);

it('accepts file names that are not valid UTF-8', function () {
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), Fixtures::big5Name()));

    expect($stored->extension)->toBe('jpg')
        ->and(mb_check_encoding($stored->originalName, 'UTF-8'))->toBeTrue();
});

it('validates every extension segment before truncating the name', function () {
    $name = str_repeat('a', 250).'.php.jpg';

    expect(reasonOf(fn () => MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), $name))))
        ->toBe(RejectedUploadException::BLOCKED_EXTENSION);
});

/* ------------------------------------------------------------ animation */

it('limits animation frames before decoding', function () {
    config()->set('media-upload.image.animation', true);
    config()->set('media-upload.image.max_frames', 3);

    expect(reasonOf(fn () => MediaUpload::store(Fixtures::upload(Fixtures::animatedGif(4), 'a.gif'))))
        ->toBe(RejectedUploadException::IMAGE_TOO_LARGE);

    expect(MediaUpload::store(Fixtures::upload(Fixtures::animatedGif(3), 'a.gif'))->mime)->toBe('image/gif');
});

it('limits the pixels of all frames together', function () {
    config()->set('media-upload.image.animation', true);
    config()->set('media-upload.image.max_animation_pixels', 20 * 20 * 2);

    expect(reasonOf(fn () => MediaUpload::store(Fixtures::upload(Fixtures::animatedGif(3), 'a.gif'))))
        ->toBe(RejectedUploadException::IMAGE_TOO_LARGE);
});

/* ------------------------------------------------------------- scanning */

it('never stores files that are too large to scan', function () {
    config()->set('media-upload.virus_scan.fail_closed', false);
    app()->instance(VirusScanner::class, new class implements VirusScanner {
        public function scan($stream): ?string
        {
            throw new ScanLimitExceededException('too big');
        }
    });

    expect(reasonOf(fn () => MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'))))
        ->toBe(RejectedUploadException::TOO_LARGE_TO_SCAN)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('checks the ClamAV size limit before streaming', function () {
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, str_repeat('x', 2048));
    rewind($stream);

    // Port 1 is never clamd: the limit must be enforced before connecting.
    (new ClamAvScanner('tcp://127.0.0.1:1', 1, maxBytes: 1024))->scan($stream);
})->throws(ScanLimitExceededException::class);

/* ---------------------------------------------------------------- queue */

it('retries queued uploads when the scanner is temporarily down', function () {
    Queue::fake();
    Event::fake([UploadRejected::class, UploadStored::class]);
    app()->instance(VirusScanner::class, new class implements VirusScanner {
        public function scan($stream): ?string
        {
            throw new ScannerUnavailableException('restarting');
        }
    });

    $pending = MediaUpload::storeAsync(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $job = pushedJob();
    $job->setJob($queueJob = new QueueJobStub());

    $job->handle(app(MediaUploader::class));

    expect($queueJob->releasedWith)->toBe(10)
        ->and(Storage::disk('local')->exists('quarantine/'.$pending->id))->toBeTrue();
    Event::assertNotDispatched(UploadRejected::class);

    // Last attempt: give up and report it.
    $queueJob->attempt = 3;
    $job->handle(app(MediaUploader::class));

    Event::assertDispatched(UploadRejected::class, fn ($e) => $e->reason === RejectedUploadException::SCAN_UNAVAILABLE);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('does not store a queued upload twice when a listener fails', function () {
    Queue::fake();
    $calls = 0;
    Event::listen(UploadStored::class, function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('database down');
        }
    });

    $pending = MediaUpload::storeAsync(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $job = pushedJob();

    expect(fn () => $job->handle(app(MediaUploader::class)))->toThrow(RuntimeException::class);
    $job->handle(app(MediaUploader::class)); // retry

    $stored = array_values(array_filter(Storage::disk('local')->allFiles(), fn ($f) => str_starts_with($f, 'uploads/')));
    expect($calls)->toBe(2)
        ->and($stored)->toHaveCount(1)
        ->and(Storage::disk('local')->files('quarantine'))->toBe([]);
});

it('does not report a stored upload as rejected when only delivery failed', function () {
    Queue::fake();
    Event::listen(UploadStored::class, fn () => throw new RuntimeException('down'));
    Event::fake([UploadRejected::class]);

    MediaUpload::storeAsync(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $job = pushedJob();

    try {
        $job->handle(app(MediaUploader::class));
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    Event::assertNotDispatched(UploadRejected::class);
    expect(Storage::disk('local')->files('quarantine'))->toBe([]);
});

it('rejects unserializable values nested in hook parameters before quarantining', function () {
    Queue::fake();

    expect(fn () => MediaUpload::profile('image')
        ->hook('validated', [CodePulse2020\MediaUpload\Transformers\Resize::class, ['width' => fn () => 1]])
        ->storeAsync(Fixtures::upload(Fixtures::jpeg(), 'a.jpg')))->toThrow(NonSerializableHookException::class);

    Queue::assertNothingPushed();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

/* -------------------------------------------------------------- serving */

it('honours a profile attachment policy in proxy URLs', function () {
    config()->set('media-upload.profiles.scans', ['mimes' => ['image/jpeg'], 'max_size' => 1024, 'disposition' => 'attachment']);

    $stored = MediaUpload::profile('scans')->store(Fixtures::upload(Fixtures::jpeg(), 'scan.jpg'));
    $url = $stored->url();

    expect($this->get($url)->headers->get('Content-Disposition'))->toStartWith('attachment');

    // Removing the flag breaks the signature.
    $this->get(str_replace('&download=1', '', $url))->assertForbidden();
});

it('uses the stored extension for download names', function () {
    $stored = MediaUpload::profile('avatar')->store(Fixtures::upload(Fixtures::jpeg(800, 800), 'portrait.jpg'));

    expect($this->get($stored->url())->headers->get('Content-Disposition'))->toContain('portrait.webp');
});

it('serves AVIF with its real type even without a mime_map entry', function () {
    config()->set('media-upload.mime_map', array_diff_key(config('media-upload.mime_map'), ['image/avif' => true]));
    Storage::disk('local')->put('uploads/x.avif', 'avif-bytes');

    $response = $this->get(MediaUpload::url('uploads/x.avif'));

    expect($response->headers->get('Content-Type'))->toBe('image/avif')
        ->and(app(ServePolicy::class)->mimeForPath('a.avif'))->toBe('image/avif');
});

it('never caches beyond the URL lifetime', function () {
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    $cache = $this->get($stored->url(1))->headers->get('Cache-Control');
    preg_match('/max-age=(\d+)/', (string) $cache, $match);
    expect((int) ($match[1] ?? PHP_INT_MAX))->toBeLessThanOrEqual(60);

    config()->set('media-upload.serve.cache', false);
    expect($this->get($stored->url())->headers->get('Cache-Control'))->toContain('no-store');
});

it('keeps signature validation when serve.middleware is customised', function () {
    config()->set('media-upload.serve.middleware', ['throttle:60,1']);
    $this->refreshApplication();
    Storage::fake('local');

    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    $this->get('/media-upload/files/'.$stored->path.'?disk=local')->assertForbidden();
    $this->get($stored->url())->assertOk();
});

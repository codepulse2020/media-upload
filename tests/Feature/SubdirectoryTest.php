<?php

use CodePulse2020\MediaUpload\Events\UploadStored;
use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse2020\MediaUpload\Facades\MediaUpload;
use CodePulse2020\MediaUpload\Jobs\ProcessQuarantinedUpload;
use CodePulse2020\MediaUpload\MediaUploader;
use CodePulse2020\MediaUpload\Tests\Fixtures;
use CodePulse2020\MediaUpload\UploadContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('media-upload.profiles.hero', ['mimes' => ['image/jpeg'], 'max_size' => 1024, 'directory' => 'site/hero']);
    config()->set('media-upload.profiles.flat', ['mimes' => ['image/jpeg'], 'max_size' => 1024, 'directory' => 'site/flat', 'date_directory' => null]);
});

it('stores inside the sub directory, before the date layer', function () {
    $dated = MediaUpload::profile('hero')->subdirectory('events/2026')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $flat = MediaUpload::profile('flat')->subdirectory('events/2026')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $root = MediaUpload::profile('flat')->subdirectory('')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($dated->path)->toStartWith('site/hero/events/2026/'.date('Y/m').'/')
        ->and($flat->path)->toMatch('#^site/flat/events/2026/[0-9a-z]{26}\.jpg$#')
        ->and($root->path)->toMatch('#^site/flat/[0-9a-z]{26}\.jpg$#')
        ->and(MediaUpload::delete($dated))->toBeTrue()
        ->and(MediaUpload::delete($flat))->toBeTrue();
});

it('rejects unsafe sub directories with invalid_directory', function (string $path) {
    try {
        MediaUpload::profile('hero')->subdirectory($path);
        $this->fail('Expected a RejectedUploadException.');
    } catch (RejectedUploadException $e) {
        expect($e->reason)->toBe(RejectedUploadException::INVALID_DIRECTORY);
    }
})->with([
    'parent' => ['../other'],
    'nested parent' => ['events/../../x'],
    'dot' => ['./events'],
    'absolute' => ['/events'],
    'trailing slash' => ['events/'],
    'empty segment' => ['events//2026'],
    'backslash' => ['events\\2026'],
    'space' => ['my events'],
    'dot in name' => ['events.2026'],
]);

it('keeps hooks inside the sub directory', function () {
    $store = fn (string $directory) => MediaUpload::profile('hero')
        ->subdirectory('events')
        ->hook('before_store', function (UploadContext $ctx, Closure $next) use ($directory) {
            expect($ctx->subdirectory())->toBe('events');
            $ctx->directory = $directory;

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($store('site/hero/events/covers')->path)->toStartWith('site/hero/events/covers/');

    // Siblings in the profile directory and managed directories are off limits once a sub directory is set.
    config()->set('media-upload.managed_directories', ['shops']);
    expect(fn () => $store('site/hero/other'))->toThrow(InvalidConfigException::class)
        ->and(fn () => $store('shops/1'))->toThrow(InvalidConfigException::class);
});

it('does not let hooks change the sub directory', function () {
    expect(fn () => MediaUpload::profile('hero')
        ->subdirectory('events')
        ->hook('before_store', function (UploadContext $ctx, Closure $next) {
            $ctx->setSubdirectory(null);

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg')))->toThrow(LogicException::class);
});

it('claims pending uploads into the sub directory', function () {
    $pending = MediaUpload::profile('flat')->subdirectory('drafts')->pending()->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($pending->path)->toStartWith('pending/flat/')
        ->and(MediaUpload::claim($pending->token())->path)->toMatch('#^site/flat/drafts/[0-9a-z]{26}\.jpg$#');
});

it('carries the sub directory through the queue', function () {
    Queue::fake();
    Event::fake([UploadStored::class]);

    MediaUpload::profile('flat')->subdirectory('events/2026')->storeAsync(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    $job = null;
    Queue::assertPushed(ProcessQuarantinedUpload::class, function ($pushed) use (&$job) {
        $job = $pushed;

        return true;
    });

    unserialize(serialize($job))->handle(app(MediaUploader::class));

    Event::assertDispatched(UploadStored::class, fn ($e) => str_starts_with($e->file->path, 'site/flat/events/2026/')
        && Storage::disk('local')->exists($e->file->path));
});

it('keeps sub directories of private profiles out of the public prefix', function (string $directory, string $public, string $subdirectory) {
    config()->set('media-upload.public.directory', $public);
    config()->set('media-upload.profiles.docs', ['mimes' => ['image/jpeg'], 'max_size' => 1024, 'directory' => $directory]);

    foreach ([false, true] as $pending) {
        try {
            MediaUpload::profile('docs')->subdirectory($subdirectory)->pending($pending)->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
            $this->fail('Expected a RejectedUploadException.');
        } catch (RejectedUploadException $e) {
            expect($e->reason)->toBe(RejectedUploadException::INVALID_DIRECTORY);
        }
    }

    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'root profile directory' => ['', 'public', 'public'],
    'parent of the public prefix' => ['media', 'media/public', 'public'],
    'nested' => ['media', 'media/public', 'public/x'],
]);

it('still lets public profiles use sub directories inside their prefix', function () {
    $stored = MediaUpload::profile('public_image')->subdirectory('banners')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($stored->path)->toStartWith('public/uploads/public_image/banners/');
});

it('does not enforce a public prefix when public.directory is empty', function () {
    config()->set('media-upload.public.directory', '');

    expect(MediaUpload::profile('public_image')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'))->path)
        ->toStartWith('uploads/public_image/');
});

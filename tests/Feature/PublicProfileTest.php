<?php

use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Facades\MediaUpload;
use CodePulse\MediaUpload\Tests\Fixtures;
use CodePulse\MediaUpload\UploadContext;
use Illuminate\Support\Facades\Storage;

it('stores public profiles under the public prefix with a permanent URL', function () {
    config()->set('media-upload.public.url', 'https://cdn.example.com/assets/');

    $stored = MediaUpload::profile('public_image')->store(Fixtures::upload(Fixtures::jpegWithExif('SECRET-GPS'), 'a.jpg'));

    expect($stored->visibility)->toBe('public')
        ->and($stored->path)->toStartWith('public/uploads/public_image/')
        ->and($stored->url())->toBe('https://cdn.example.com/assets/'.$stored->path)
        ->and(MediaUpload::url($stored->path))->toBe('https://cdn.example.com/assets/'.$stored->path)
        ->and(Storage::disk('local')->get($stored->path))->not->toContain('SECRET-GPS');
});

it('falls back to the disk URL for public files', function () {
    $stored = MediaUpload::profile('public_image')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($stored->url())->toBe(Storage::disk('local')->url($stored->path))
        ->and($stored->url())->not->toContain('signature=');
});

it('writes public profiles to the public disk when configured', function () {
    Storage::fake('public');
    config()->set('filesystems.disks.public.visibility', 'public');
    config()->set('media-upload.public.disk', 'public');

    $stored = MediaUpload::profile('public_image')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($stored->disk)->toBe('public')
        ->and(Storage::disk('public')->exists($stored->path))->toBeTrue();
});

it('only allows re-encoded images in public profiles', function (array $mimes) {
    config()->set('media-upload.profiles.leaky', ['mimes' => $mimes, 'max_size' => 100, 'visibility' => 'public']);

    MediaUpload::profile('leaky');
})->with([
    'pdf' => [['application/pdf']],
    'svg' => [['image/svg+xml']],
    'text' => [['image/png', 'text/plain']],
])->throws(InvalidConfigException::class);

it('keeps private profiles out of the public prefix', function () {
    config()->set('media-upload.profiles.sneaky', ['mimes' => ['application/pdf'], 'max_size' => 100, 'directory' => 'public/docs']);

    MediaUpload::profile('sneaky');
})->throws(InvalidConfigException::class);

it('does not let hooks move files across the public boundary', function (string $profile, string $directory) {
    MediaUpload::profile($profile)
        ->hook('before_store', function (UploadContext $ctx, Closure $next) use ($directory) {
            $ctx->directory = $directory;

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
})->with([
    'public file to private area' => ['public_image', 'shops/1'],
    'private file to public area' => ['image', 'public/shops/1'],
])->throws(InvalidConfigException::class);

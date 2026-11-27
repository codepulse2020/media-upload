<?php

use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse2020\MediaUpload\Facades\MediaUpload;
use CodePulse2020\MediaUpload\Tests\Fixtures;
use CodePulse2020\MediaUpload\Tests\RemoteUploadedFile;
use Illuminate\Support\Facades\Storage;

function tempCopies(): array
{
    return glob(sys_get_temp_dir().'/media-upload-src-*') ?: [];
}

it('exposes read-only profile rules', function () {
    $rules = MediaUpload::rules('avatar');

    expect($rules->mimes)->toBe(['image/jpeg', 'image/png', 'image/webp'])
        ->and($rules->extensions)->toBe(['jpg', 'jpeg', 'png', 'webp'])
        ->and($rules->maxSizeKb)->toBe(2048)
        ->and($rules->maxWidth)->toBe(4096)
        ->and($rules->visibility)->toBe('private')
        ->and($rules->imagesOnly)->toBeTrue()
        ->and($rules->accept())->toBe('image/jpeg,image/png,image/webp');

    expect(MediaUpload::rules('document')->imagesOnly)->toBeFalse()
        ->and(MediaUpload::rules('document')->maxWidth)->toBeNull();
});

it('exposes the resolved disk and root directory of a profile', function () {
    config()->set('filesystems.disks.media', ['driver' => 'local', 'root' => storage_path('app/media')]);
    Storage::fake('media');
    config()->set('media-upload.profiles.hero', [
        'mimes' => ['image/jpeg'], 'max_size' => 1024, 'disk' => 'media', 'directory' => 'site/hero',
    ]);
    config()->set('media-upload.profiles.banner', [
        'mimes' => ['image/jpeg'], 'max_size' => 1024, 'visibility' => 'public', 'directory' => 'media',
    ]);

    expect(MediaUpload::rules('avatar'))
        ->disk->toBe('local')
        ->directory->toBe('uploads/avatar');
    expect(MediaUpload::rules('public_image')->directory)->toBe('public/uploads/public_image');
    expect(MediaUpload::rules('banner')->directory)->toBe('public/media');

    $rules = MediaUpload::rules('hero');
    $stored = MediaUpload::profile('hero')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($rules->disk)->toBe('media')
        ->and($rules->directory)->toBe('site/hero')
        ->and($stored->disk)->toBe($rules->disk)
        ->and($stored->path)->toStartWith($rules->directory.'/'.date('Y/m').'/')
        ->and(Storage::disk('media')->exists($stored->path))->toBeTrue();

    config()->set('media-upload.public.disk', 'media');
    expect(MediaUpload::rules('banner')->disk)->toBe('media');
});

it('lets profiles change or drop the date sub directory', function () {
    config()->set('media-upload.profiles.flat', ['mimes' => ['image/jpeg'], 'max_size' => 1024, 'date_directory' => null]);
    config()->set('media-upload.profiles.daily', ['mimes' => ['image/jpeg'], 'max_size' => 1024, 'date_directory' => 'Y/m/d']);

    $flat = MediaUpload::profile('flat')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $daily = MediaUpload::profile('daily')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($flat->path)->toMatch('#^uploads/flat/[0-9a-z]{26}\.jpg$#')
        ->and(MediaUpload::rules('flat')->dateDirectory)->toBeNull()
        ->and($daily->path)->toStartWith('uploads/daily/'.date('Y/m/d').'/')
        ->and(MediaUpload::rules('daily')->dateDirectory)->toBe('Y/m/d')
        ->and(MediaUpload::rules('avatar')->dateDirectory)->toBe('Y/m');

    config()->set('media-upload.date_directory', '');
    expect(MediaUpload::profile('avatar')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'))->path)
        ->toMatch('#^uploads/avatar/[0-9a-z]{26}\.webp$#');
});

it('rejects date_directory formats that could produce unsafe path segments', function (mixed $format) {
    config()->set('media-upload.profiles.bad', ['mimes' => ['image/jpeg'], 'max_size' => 1024, 'date_directory' => $format]);

    MediaUpload::rules('bad');
})->with([
    'parent segment' => ['../Y'],
    'leading slash' => ['/Y/m'],
    'empty segment' => ['Y//m'],
    'trailing slash' => ['Y/m/'],
    'escaped literal' => ['\\.\\.'],
    'iso 8601 (colons)' => ['c'],
    'timezone (may contain /)' => ['e'],
    'month name' => ['F'],
    'not a string' => [['Y']],
])->throws(InvalidConfigException::class);

it('stores remote temporary uploads through readStream()', function () {
    Storage::disk('local')->put('livewire-tmp/abc', Fixtures::jpegWithExif('SECRET-GPS'));
    $before = tempCopies();

    $stored = MediaUpload::store(new RemoteUploadedFile('local', 'livewire-tmp/abc', 'remote.jpg'));

    expect($stored->mime)->toBe('image/jpeg')
        ->and($stored->originalName)->toBe('remote.jpg')
        ->and(Storage::disk('local')->get($stored->path))->not->toContain('SECRET-GPS')
        ->and(tempCopies())->toBe($before);
});

it('stops copying remote files at the size limit', function () {
    config()->set('media-upload.profiles.image.max_size', 1);
    Storage::disk('local')->put('livewire-tmp/big', str_repeat('x', 4096));
    $before = tempCopies();

    try {
        MediaUpload::store(new RemoteUploadedFile('local', 'livewire-tmp/big', 'big.jpg'));
        $this->fail('Expected rejection.');
    } catch (RejectedUploadException $e) {
        expect($e->reason)->toBe(RejectedUploadException::TOO_LARGE);
    }

    expect(tempCopies())->toBe($before);
});

it('stops reading a stream once the size limit is exceeded', function () {
    config()->set('media-upload.profiles.image.max_size', 1);
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, str_repeat('x', 5 * 1024 * 1024));
    rewind($stream);

    expect(fn () => MediaUpload::profile('image')->storeFromStream($stream, 'a.jpg'))->toThrow(RejectedUploadException::class);

    // Read one 1 MB chunk, not the whole 5 MB.
    expect(ftell($stream))->toBeLessThanOrEqual(1024 * 1024);
});

it('stores from a stream and from another disk', function () {
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, Fixtures::jpeg());
    rewind($stream);

    expect(MediaUpload::profile('image')->storeFromStream($stream, 'a.jpg')->mime)->toBe('image/jpeg');

    Storage::disk('local')->put('incoming/direct.png', Fixtures::png());
    $stored = MediaUpload::profile('image')->storeFromDisk('local', 'incoming/direct.png');

    expect($stored->mime)->toBe('image/png')
        ->and($stored->originalName)->toBe('direct.png')
        ->and(Storage::disk('local')->exists('incoming/direct.png'))->toBeTrue();
});

it('still validates what comes from a stream', function () {
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, '<?php echo 1;');
    rewind($stream);

    expect(fn () => MediaUpload::profile('image')->storeFromStream($stream, 'a.jpg'))
        ->toThrow(RejectedUploadException::class);
});

<?php

use CodePulse\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse\MediaUpload\Facades\MediaUpload;
use CodePulse\MediaUpload\Tests\Fixtures;
use CodePulse\MediaUpload\Tests\RemoteUploadedFile;
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

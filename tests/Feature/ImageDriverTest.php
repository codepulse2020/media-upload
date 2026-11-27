<?php

use CodePulse2020\MediaUpload\Facades\MediaUpload;
use CodePulse2020\MediaUpload\Support\FrameCounter;
use CodePulse2020\MediaUpload\Tests\Fixtures;
use Illuminate\Support\Facades\Storage;

/*
| The same security guarantees for both drivers. Imagick runs in CI (and
| locally when ext-imagick is installed); otherwise those cases are skipped.
*/

function useDriver(string $driver): void
{
    if (! extension_loaded($driver)) {
        test()->markTestSkipped("ext-{$driver} is not installed.");
    }

    config()->set('media-upload.image.driver', $driver);
}

dataset('drivers', ['gd', 'imagick']);

it('strips EXIF from JPEG', function (string $driver) {
    useDriver($driver);

    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpegWithExif('SECRET-GPS'), 'a.jpg'));

    expect(Storage::disk('local')->get($stored->path))->not->toContain('SECRET-GPS');
})->with('drivers');

it('strips text and EXIF chunks from PNG', function (string $driver) {
    useDriver($driver);
    $source = Fixtures::pngWithText('SECRET-PNG-TEXT');
    expect($source)->toContain('SECRET-PNG-TEXT');

    $stored = MediaUpload::store(Fixtures::upload($source, 'a.png'));
    $bytes = Storage::disk('local')->get($stored->path);

    expect($stored->mime)->toBe('image/png')
        ->and($bytes)->not->toContain('SECRET-PNG-TEXT')
        ->and($bytes)->not->toContain('tEXt')
        ->and($bytes)->not->toContain('eXIf');
})->with('drivers');

it('removes polyglot payloads', function (string $driver) {
    useDriver($driver);

    $stored = MediaUpload::store(Fixtures::upload(Fixtures::gifPolyglot(), 'a.gif'));

    expect(Storage::disk('local')->get($stored->path))->not->toContain('<?php');
})->with('drivers');

it('keeps only the first frame when animation is off', function (string $driver) {
    useDriver($driver);

    $stored = MediaUpload::store(Fixtures::upload(Fixtures::animatedGif(5), 'a.gif'));
    $path = Storage::disk('local')->path($stored->path);

    expect(FrameCounter::count($path, 'image/gif'))->toBe(1);
})->with('drivers');

it('keeps animations within the limits when enabled', function (string $driver) {
    useDriver($driver);
    config()->set('media-upload.image.animation', true);

    $stored = MediaUpload::store(Fixtures::upload(Fixtures::animatedGif(3), 'a.gif'));

    expect(FrameCounter::count(Storage::disk('local')->path($stored->path), 'image/gif'))->toBe(3);
})->with('drivers');

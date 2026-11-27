<?php

use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Exceptions\InvalidUploadTokenException;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse2020\MediaUpload\Facades\MediaUpload;
use CodePulse2020\MediaUpload\Rules\UploadToken;
use CodePulse2020\MediaUpload\Tests\Fixtures;
use CodePulse2020\MediaUpload\UploadContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/*
| Regressions for the run-1 security audit findings.
*/

function rejectionReason(callable $store): ?string
{
    try {
        $store();

        return null;
    } catch (RejectedUploadException $e) {
        return $e->reason;
    }
}

/* ------------------------------------------------------------ GIF structure */

it('refuses GIFs whose structure would let frames hide from the frame limit', function (string $driver, string $kind) {
    if (! extension_loaded($driver)) {
        $this->markTestSkipped("ext-{$driver} is not installed.");
    }

    config()->set('media-upload.image.driver', $driver);
    config()->set('media-upload.image.animation', true);
    config()->set('media-upload.image.max_frames', 3);

    $bytes = $kind === 'desynced extension' ? Fixtures::gifWithDesyncedExtension(20) : Fixtures::gifWithJunkByte(20);

    expect(rejectionReason(fn () => MediaUpload::store(Fixtures::upload($bytes, 'a.gif'))))->not->toBeNull();
})->with(['gd', 'imagick'])->with(['desynced extension', 'junk byte']);

it('refuses GIF frames larger than the logical screen', function (string $driver) {
    if (! extension_loaded($driver)) {
        $this->markTestSkipped("ext-{$driver} is not installed.");
    }

    config()->set('media-upload.image.driver', $driver);
    config()->set('media-upload.image.max_pixels', 10_000);

    $reason = rejectionReason(fn () => MediaUpload::store(Fixtures::upload(Fixtures::gifWithOversizedFrame(3000, 3000), 'a.gif')));

    expect($reason)->toBe(RejectedUploadException::CORRUPT_FILE);
})->with(['gd', 'imagick']);

it('still accepts well-formed animated GIFs within the limits', function () {
    config()->set('media-upload.image.animation', true);
    config()->set('media-upload.image.max_frames', 5);

    expect(MediaUpload::store(Fixtures::upload(Fixtures::animatedGif(3), 'a.gif'))->mime)->toBe('image/gif');
});

/* ------------------------------------------------------------ content-addressed names */

it('reserves SHA-256 shaped names for content-addressed files', function () {
    config()->set('media-upload.filename.generator', 'hash');

    $squat = fn () => MediaUpload::profile('image')
        ->hook('before_store', function (UploadContext $ctx, Closure $next) {
            $ctx->filename = str_repeat('ab', 32);

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($squat)->toThrow(InvalidConfigException::class)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    // Other generators keep accepting such names: nothing deduplicates onto them.
    config()->set('media-upload.filename.generator', 'ulid');
    expect($squat()->path)->toEndWith(str_repeat('ab', 32).'.jpg');
});

it('never rolls back a content-addressed file another upload deduplicated onto', function () {
    config()->set('media-upload.filename.generator', 'hash');
    $victim = null;

    $attempt = function () use (&$victim) {
        MediaUpload::profile('image')
        ->ownedBy('attacker')
        ->hook('after_store', function (UploadContext $ctx, Closure $next) use (&$victim) {
            // Another user stores the same bytes while this upload's after_store hooks run.
            $victim = MediaUpload::profile('image')->ownedBy('victim')->store(Fixtures::upload(Fixtures::jpeg(), 'b.jpg'));

            throw new RuntimeException('after_store failed');
        })
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    };

    expect($attempt)->toThrow(RuntimeException::class);
    expect($victim->owner)->toBe('victim')
        ->and(Storage::disk('local')->exists($victim->path))->toBeTrue();
});

/* ------------------------------------------------------------ stale pending tokens */

it('does not let a stale pending token claim another owner\'s file at the same name', function (string $removal) {
    config()->set('media-upload.managed_directories', ['covers']);

    $pending = fn (string $color, int $owner) => MediaUpload::profile('image')->pending()->ownedBy($owner)
        ->hook('before_store', function (UploadContext $ctx, Closure $next) {
            $ctx->directory = 'covers';
            $ctx->filename = 'shop-1';

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(200, 100, $color), 'a.jpg'));

    $attacker = $pending('blue', 2);
    $token = $attacker->token();

    // The attacker's pending source disappears while the token is still valid.
    $removal === 'cancel'
        ? MediaUpload::delete($attacker)
        : Storage::disk('local')->delete($attacker->path);

    $owner = $pending('red', 1);
    $claimed = MediaUpload::claim($owner->token(), 'image', 1);

    expect(fn () => MediaUpload::claim($token, 'image', 2))->toThrow(InvalidUploadTokenException::class)
        ->and(fn () => MediaUpload::fromToken($token, 'image', 2))->toThrow(InvalidUploadTokenException::class)
        ->and(Validator::make(['t' => $token], ['t' => new UploadToken('image', owner: 2)])->fails())->toBeTrue()
        ->and(MediaUpload::claim($owner->token(), 'image', 1)->path)->toBe($claimed->path); // still idempotent
})->with(['cancel', 'prune']);

/* ------------------------------------------------------------ OOXML main part and macros */

function officeProfile(): void
{
    config()->set('media-upload.profiles.office', [
        'mimes' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
        ],
        'max_size' => 1024,
    ]);
}

function contentTypes(string ...$overrides): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .implode('', array_map(fn ($o) => '<Override '.$o.'/>', $overrides))
        .'</Types>';
}

it('resolves the OOXML main part from its relationship, not the first Override', function () {
    officeProfile();

    // Decoy plain-xlsx Override first, the real main part is macro-enabled.
    $bytes = Fixtures::ooxml('xlsx', ['xl/payload.bin' => 'vba'], contentTypes: contentTypes(
        'PartName="/xl/decoy.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"',
        'PartName="/xl/workbook.xml" ContentType="application/vnd.ms-excel.sheet.macroEnabled.main+xml"',
        'PartName="/xl/payload.bin" ContentType="application/vnd.ms-office.vbaProject"',
    ));

    expect(rejectionReason(fn () => MediaUpload::profile('office')->store(Fixtures::upload($bytes, 'report.xlsx'))))
        ->toBe(RejectedUploadException::UNSAFE_CONTENT);
});

it('detects macros by content type, whatever the part is called', function (string $type) {
    officeProfile();

    $bytes = Fixtures::ooxml('xlsx', ['xl/payload.bin' => 'x'], contentTypes: contentTypes(
        'PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"',
        'PartName="/xl/payload.bin" ContentType="'.$type.'"',
    ));

    expect(rejectionReason(fn () => MediaUpload::profile('office')->store(Fixtures::upload($bytes, 'report.xlsx'))))
        ->toBe(RejectedUploadException::UNSAFE_CONTENT);
})->with([
    'renamed VBA project' => ['application/vnd.ms-office.vbaProject'],
    'Excel 4.0 macro sheet' => ['application/vnd.ms-excel.macrosheet+xml'],
]);

it('refuses OOXML packages whose main part is not where the relationship points', function () {
    officeProfile();

    // The Override names a part the officeDocument relationship does not target.
    $bytes = Fixtures::ooxml('xlsx', contentTypes: contentTypes(
        'PartName="/xl/other.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"',
    ));

    expect(rejectionReason(fn () => MediaUpload::profile('office')->store(Fixtures::upload($bytes, 'report.xlsx'))))
        ->not->toBeNull();

    // The honest package still passes.
    expect(MediaUpload::profile('office')->store(Fixtures::upload(Fixtures::ooxml('xlsx'), 'report.xlsx'))->extension)->toBe('xlsx');
});

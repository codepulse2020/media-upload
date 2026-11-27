<?php

use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse2020\MediaUpload\Facades\MediaUpload;
use CodePulse2020\MediaUpload\Tests\Fixtures;
use CodePulse2020\MediaUpload\UploadContext;
use Illuminate\Support\Facades\Storage;

function rejection(callable $callback): ?string
{
    try {
        $callback();
    } catch (Rejected $e) {
        return $e->reason;
    }

    return null;
}

/* ----------------------------------------------------------------- delete */

it('deletes its own files', function () {
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect(MediaUpload::delete($stored))->toBeTrue()
        ->and(Storage::disk('local')->exists($stored->path))->toBeFalse()
        ->and(MediaUpload::delete($stored))->toBeFalse()
        ->and(MediaUpload::delete(MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'b.jpg'))->path))->toBeTrue();
});

it('refuses to delete paths it does not manage', function (string $path, ?string $disk) {
    Storage::disk('local')->put('imports/secret.csv', 'x');

    MediaUpload::delete($path, $disk);
})->with([
    'foreign directory' => ['imports/secret.csv', null],
    'traversal' => ['uploads/image/../../imports/secret.csv', null],
    'unknown disk' => ['uploads/image/x.jpg', 's3-elsewhere'],
])->throws(InvalidArgumentException::class);

it('requires force for shared content-addressed files', function () {
    config()->set('media-upload.filename.generator', 'hash');
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect(fn () => MediaUpload::delete($stored))->toThrow(LogicException::class)
        ->and(MediaUpload::delete($stored, force: true))->toBeTrue();
});

it('deletes hook chosen directories once they are declared', function () {
    $stored = MediaUpload::profile('image')
        ->hook('before_store', function (UploadContext $ctx, Closure $next) {
            $ctx->directory = 'shops/1';

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect(fn () => MediaUpload::delete($stored))->toThrow(InvalidArgumentException::class);

    config()->set('media-upload.managed_directories', ['shops']);
    expect(MediaUpload::delete($stored))->toBeTrue();
});

/* ----------------------------------------------------------------- office */

beforeEach(function () {
    config()->set('media-upload.profiles.office', [
        'mimes' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'max_size' => 1024,
    ]);
});

it('stores Office documents as private attachments', function (string $type, string $name) {
    $stored = MediaUpload::profile('office')->store(Fixtures::upload(Fixtures::ooxml($type), $name));

    expect($stored->extension)->toBe(pathinfo($name, PATHINFO_EXTENSION))
        ->and($this->get($stored->url())->headers->get('Content-Disposition'))->toStartWith('attachment');
})->with([['xlsx', 'report.xlsx'], ['docx', 'memo.docx']]);

it('identifies Office files even when libmagic only sees a zip', function () {
    $bytes = Fixtures::ooxml('xlsx', [], contentTypesLast: true);
    expect((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes))->toBe('application/zip');

    expect(MediaUpload::profile('office')->store(Fixtures::upload($bytes, 'report.xlsx'))->mime)
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('refuses macros unless the profile allows them', function () {
    expect(rejection(fn () => MediaUpload::profile('office')->store(Fixtures::upload(Fixtures::ooxml('xlsm'), 'report.xlsm'))))
        ->toBe(Rejected::UNSAFE_CONTENT);

    // A VBA project inside a file declared as .xlsx counts as macros too.
    expect(rejection(fn () => MediaUpload::profile('office')->store(
        Fixtures::upload(Fixtures::ooxml('xlsx', ['xl/vbaProject.bin' => 'vba']), 'report.xlsx')
    )))->toBe(Rejected::UNSAFE_CONTENT);

    config()->set('media-upload.profiles.office.office', ['allow_macros' => true]);
    expect(MediaUpload::profile('office')->store(Fixtures::upload(Fixtures::ooxml('xlsm'), 'report.xlsm'))->extension)->toBe('xlsm');
});

it('does not let a macro workbook pass as .xlsx', function () {
    // libmagic reports this xlsm as a plain xlsx.
    expect(rejection(fn () => MediaUpload::profile('office')->store(Fixtures::upload(Fixtures::ooxml('xlsm'), 'report.xlsx'))))
        ->toBe(Rejected::EXTENSION_MISMATCH);
});

it('refuses zip bombs and hostile content types', function () {
    config()->set('media-upload.office.max_uncompressed_size', 1024 * 1024);

    expect(rejection(fn () => MediaUpload::profile('office')->store(
        Fixtures::upload(Fixtures::ooxml('xlsx', ['xl/big.xml' => str_repeat('a', 3 * 1024 * 1024)]), 'big.xlsx')
    )))->toBe(Rejected::UNSAFE_CONTENT);

    $doctype = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';
    expect(rejection(fn () => MediaUpload::profile('office')->store(
        Fixtures::upload(Fixtures::ooxml('xlsx', [], false, $doctype), 'x.xlsx')
    )))->not->toBeNull();
});

it('counts what really decompresses, not what the zip headers claim', function () {
    config()->set('media-upload.office.max_uncompressed_size', 1024 * 1024);

    expect(rejection(fn () => MediaUpload::profile('office')->store(Fixtures::upload(Fixtures::ooxmlWithLyingSize(), 'lie.xlsx'))))
        ->toBe(Rejected::UNSAFE_CONTENT);
});

it('refuses DTDs hidden behind a UTF-16 encoding', function () {
    $bytes = Fixtures::ooxml('xlsx', [], false, Fixtures::utf16ContentTypesWithDoctype());

    expect(rejection(fn () => MediaUpload::profile('office')->store(Fixtures::upload($bytes, 'x.xlsx'))))
        ->toBe(Rejected::CORRUPT_FILE);
});

/* -------------------------------------------------------------------- pdf */

it('accepts report PDFs with a harmless OpenAction', function () {
    expect(MediaUpload::profile('document')->store(Fixtures::upload(Fixtures::pdfWithOpenAction(), 'report.pdf'))->mime)
        ->toBe('application/pdf');
});

it('sees through escaped PDF names', function () {
    expect(rejection(fn () => MediaUpload::profile('document')->store(Fixtures::upload(Fixtures::pdfWithEscapedJavascript(), 'x.pdf'))))
        ->toBe(Rejected::UNSAFE_CONTENT);
});

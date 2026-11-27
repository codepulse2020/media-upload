<?php

namespace CodePulse2020\MediaUpload\Support;

use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use DOMDocument;
use ZipArchive;

/**
 * Identifies Office Open XML files (xlsx, xlsm, docx, pptx …) from their
 * [Content_Types].xml instead of libmagic, whose answer for OOXML varies
 * between versions (the specific type, application/zip or octet-stream).
 *
 * @internal
 */
final class OoxmlInspector
{
    /** Main part content type => [file MIME type, macro-enabled] */
    public const MAIN_PARTS = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false],
        'application/vnd.ms-excel.sheet.macroEnabled.main+xml' => ['application/vnd.ms-excel.sheet.macroEnabled.12', true],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
        'application/vnd.ms-word.document.macroEnabled.main+xml' => ['application/vnd.ms-word.document.macroEnabled.12', true],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', false],
        'application/vnd.ms-powerpoint.presentation.macroEnabled.main+xml' => ['application/vnd.ms-powerpoint.presentation.macroEnabled.12', true],
    ];

    public const EXTENSIONS = ['xlsx', 'xlsm', 'docx', 'docm', 'pptx', 'pptm'];

    /** Content types Office executes as macros, whatever the part is called. */
    private const MACRO_TYPES = [
        'application/vnd.ms-office.vbaproject',
        'application/vnd.ms-office.vbaprojectsignature',
        'application/vnd.ms-excel.macrosheet+xml',
        'application/vnd.ms-excel.intlmacrosheet+xml',
    ];

    private const CONTENT_TYPES_MAX = 1024 * 1024;

    /**
     * @return array{mime: string, macros: bool}
     *
     * @throws Rejected
     */
    public static function inspect(string $path, int $maxEntries = 10_000, int $maxUncompressed = 100 * 1024 * 1024): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new InvalidConfigException('Office uploads require the zip PHP extension.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw Rejected::because(Rejected::CORRUPT_FILE, 'The document could not be read.');
        }

        try {
            if ($zip->numFiles > $maxEntries) {
                throw Rejected::because(Rejected::UNSAFE_CONTENT, 'The document contains too many parts.');
            }

            $names = [];
            $declared = 0;
            $macros = false;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    throw Rejected::because(Rejected::CORRUPT_FILE, 'The document could not be read.');
                }

                $names[] = $stat['name'];
                $declared += (int) $stat['size'];

                // A VBA project marks macros even inside a file declared as .xlsx/.docx.
                if (str_contains(strtolower($stat['name']), 'vbaproject')) {
                    $macros = true;
                }
            }

            // Cheap early answer for honest headers.
            if ($declared > $maxUncompressed) {
                throw Rejected::because(Rejected::UNSAFE_CONTENT, 'The document is too large once decompressed.');
            }

            // Declared sizes are written by the uploader and can lie, so count
            // what actually decompresses, stopping at the limit.
            self::assertDecompressedWithin($zip, $names, $maxUncompressed);

            $types = self::parse(self::smallPart($zip, '[Content_Types].xml'));
            $main = self::mainPart($zip, $types);
            $macros = $macros || self::declaresMacros($types);
        } finally {
            $zip->close();
        }

        [$mime, $macroType] = self::MAIN_PARTS[$main];

        return ['mime' => $mime, 'macros' => $macros || $macroType];
    }

    /**
     * @param  list<string>  $names
     */
    private static function assertDecompressedWithin(ZipArchive $zip, array $names, int $limit): void
    {
        $total = 0;

        foreach ($names as $name) {
            if (str_ends_with($name, '/')) {
                continue; // directory entry
            }

            $stream = $zip->getStream($name);

            if ($stream === false) {
                throw Rejected::because(Rejected::CORRUPT_FILE, 'The document could not be read.');
            }

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);

                    if ($chunk === false) {
                        throw Rejected::because(Rejected::CORRUPT_FILE, 'The document could not be read.');
                    }

                    $total += strlen($chunk);

                    if ($total > $limit) {
                        throw Rejected::because(Rejected::UNSAFE_CONTENT, 'The document is too large once decompressed.');
                    }

                    if ($chunk === '') {
                        break;
                    }
                }
            } finally {
                fclose($stream);
            }
        }
    }

    /** Reads a small XML part (content types, relationships) with a size cap. */
    private static function smallPart(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);

        if ($stat === false || $stat['size'] > self::CONTENT_TYPES_MAX) {
            throw Rejected::because(Rejected::CORRUPT_FILE, 'The file is not an Office document.');
        }

        return (string) $zip->getFromName($name);
    }

    private static function parse(string $xml): DOMDocument
    {
        // OOXML parts are UTF-8. Refusing other encodings up front
        // keeps a UTF-16 "<!DOCTYPE" from slipping past the byte check below.
        if ($xml === ''
            || str_starts_with($xml, "\xFF\xFE") || str_starts_with($xml, "\xFE\xFF")
            || str_contains($xml, "\0")
            || ! mb_check_encoding($xml, 'UTF-8')
            || stripos($xml, '<!DOCTYPE') !== false) {
            throw Rejected::because(Rejected::CORRUPT_FILE, 'The file is not an Office document.');
        }

        $document = new DOMDocument();

        if (! @$document->loadXML($xml, LIBXML_NONET)) {
            throw Rejected::because(Rejected::CORRUPT_FILE, 'The file is not an Office document.');
        }

        // No DTDs at all: rules out entity expansion and external entities,
        // whatever form the declaration took.
        if ($document->doctype !== null) {
            throw Rejected::because(Rejected::CORRUPT_FILE, 'The file is not an Office document.');
        }

        return $document;
    }

    /**
     * The main part is the target of the package's officeDocument relationship,
     * as Office resolves it, not whichever Override happens to come first. A
     * package declaring more than one main part type is refused outright.
     */
    private static function mainPart(ZipArchive $zip, DOMDocument $types): string
    {
        $overrides = [];
        $mainTypes = 0;

        foreach ($types->getElementsByTagName('Override') as $override) {
            $type = $override->getAttribute('ContentType');
            $overrides[strtolower($override->getAttribute('PartName'))] = $type;

            if (isset(self::MAIN_PARTS[$type])) {
                $mainTypes++;
            }
        }

        if ($mainTypes > 1) {
            throw Rejected::because(Rejected::UNSAFE_CONTENT, 'The document declares more than one main part.');
        }

        $target = null;

        foreach (self::parse(self::smallPart($zip, '_rels/.rels'))->getElementsByTagName('Relationship') as $relationship) {
            if (str_ends_with($relationship->getAttribute('Type'), '/officeDocument')) {
                if ($target !== null || strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0) {
                    throw Rejected::because(Rejected::UNSAFE_CONTENT, 'The document main part is not valid.');
                }

                $target = '/'.ltrim($relationship->getAttribute('Target'), '/');
            }
        }

        if ($target === null || $zip->statName(ltrim($target, '/')) === false) {
            throw Rejected::because(Rejected::CORRUPT_FILE, 'The file is not an Office document.');
        }

        $type = $overrides[strtolower($target)] ?? null;

        if ($type === null || ! isset(self::MAIN_PARTS[$type])) {
            throw Rejected::because(Rejected::MIME_NOT_ALLOWED, 'This Office document type is not supported.');
        }

        return $type;
    }

    /**
     * Office loads a VBA project or an Excel 4.0 macro sheet by its content
     * type, whatever the part is named, so any such declaration marks macros.
     */
    private static function declaresMacros(DOMDocument $types): bool
    {
        foreach (['Override', 'Default'] as $tag) {
            foreach ($types->getElementsByTagName($tag) as $node) {
                if (in_array(strtolower($node->getAttribute('ContentType')), self::MACRO_TYPES, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

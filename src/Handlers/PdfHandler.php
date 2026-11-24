<?php

namespace CodePulse\MediaUpload\Handlers;

use CodePulse\MediaUpload\Contracts\FileHandler;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse\MediaUpload\UploadContext;

/**
 * Basic defence only: refuses PDFs containing active content keywords.
 * /OpenAction and /AA are only triggers (often used for "open at page 1,
 * fit width"); the danger is what they point to (/JavaScript, /Launch …),
 * which stays blocked.
 * Compressed object streams can hide keywords, so serve PDFs as attachments
 * and combine this with a virus scanner for real protection.
 *
 * @api
 */
final class PdfHandler implements FileHandler
{
    /** @param list<string> $blockedKeywords */
    public function __construct(private readonly array $blockedKeywords = [])
    {
    }

    public function decode(UploadContext $ctx): void
    {
        $handle = fopen($ctx->path(), 'rb');

        if ($handle === false || fread($handle, 5) !== '%PDF-') {
            is_resource($handle) && fclose($handle);

            throw Rejected::because(Rejected::CORRUPT_FILE, 'The PDF is not valid.');
        }

        // Scan in overlapping chunks so keywords split across chunk boundaries are found.
        // "#xx" escapes can triple a keyword's length in the raw bytes.
        $overlap = 3 * max(array_map('strlen', $this->blockedKeywords ?: [''])) + 16;
        $carry = '';

        while (! feof($handle)) {
            $chunk = $carry.fread($handle, 1024 * 1024);
            $decoded = self::decodeNames($chunk);

            foreach ($this->blockedKeywords as $keyword) {
                if (preg_match('/'.preg_quote($keyword, '/').'(?![A-Za-z0-9])/', $decoded)) {
                    fclose($handle);

                    throw Rejected::because(Rejected::UNSAFE_CONTENT, 'The PDF contains active content.');
                }
            }

            $carry = substr($chunk, -$overlap);
        }

        fclose($handle);
    }

    /**
     * PDF names may escape any character as "#xx" ("/J#61vaScript" is
     * "/JavaScript"), so decode them before matching keywords.
     */
    private static function decodeNames(string $chunk): string
    {
        return (string) preg_replace_callback(
            '/#([0-9A-Fa-f]{2})/',
            fn (array $match) => chr((int) hexdec($match[1])),
            $chunk,
        );
    }

    public function encode(UploadContext $ctx): void
    {
        $ctx->setOutput(null, 'application/pdf', 'pdf');
    }
}

<?php

namespace CodePulse\MediaUpload\Handlers;

use CodePulse\MediaUpload\Contracts\FileHandler;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse\MediaUpload\Support\OoxmlInspector;
use CodePulse\MediaUpload\UploadContext;

/**
 * Office Open XML documents. Stored as-is (always private, served as
 * attachments) after checking the container: part count, decompressed size
 * and macros, which are refused unless the profile sets office.allow_macros.
 *
 * Legacy binary formats (.xls, .doc) and encrypted documents are not supported.
 *
 * @api
 */
final class OfficeHandler implements FileHandler
{
    public function decode(UploadContext $ctx): void
    {
        $options = $ctx->profile->office;

        $result = OoxmlInspector::inspect(
            $ctx->path(),
            (int) $options['max_entries'],
            (int) $options['max_uncompressed_size'],
        );

        if ($result['mime'] !== $ctx->mime()) {
            throw Rejected::because(Rejected::EXTENSION_MISMATCH, 'The file extension does not match its contents.');
        }

        if ($result['macros'] && ! $options['allow_macros']) {
            throw Rejected::because(Rejected::UNSAFE_CONTENT, 'Documents with macros are not allowed.');
        }
    }

    public function encode(UploadContext $ctx): void
    {
        $ctx->setOutput(null, (string) $ctx->mime(), $ctx->clientExtension());
    }
}

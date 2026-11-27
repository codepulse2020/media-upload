<?php

namespace CodePulse2020\MediaUpload\Pipeline;

use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse2020\MediaUpload\Support\MimeMap;
use CodePulse2020\MediaUpload\Support\OoxmlInspector;
use CodePulse2020\MediaUpload\UploadContext;
use finfo;

/**
 * Detects the real type from magic bytes. Client Content-Type is ignored.
 *
 * @internal
 */
final class SniffMimeType
{
    private const OOXML_CANDIDATES = [
        'application/zip',
        'application/octet-stream',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel.sheet.macroEnabled.12',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-word.document.macroEnabled.12',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
    ];

    public function __construct(private readonly MimeMap $mimeMap)
    {
    }

    public function __invoke(UploadContext $ctx): void
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($ctx->path());

        // libmagic's verdict on Office Open XML varies by version; read the
        // container's own declaration instead.
        if (in_array($ctx->clientExtension(), OoxmlInspector::EXTENSIONS, true)
            && in_array($mime, self::OOXML_CANDIDATES, true)) {
            $office = $ctx->profile->office;
            $mime = OoxmlInspector::inspect($ctx->path(), (int) $office['max_entries'], (int) $office['max_uncompressed_size'])['mime'];
        }

        if (! is_string($mime) || ! in_array($mime, $ctx->profile->mimes, true)) {
            throw Rejected::because(Rejected::MIME_NOT_ALLOWED, 'This file type is not allowed.');
        }

        if (! $this->mimeMap->allows($mime, $ctx->clientExtension())) {
            throw Rejected::because(Rejected::EXTENSION_MISMATCH, 'The file extension does not match its contents.');
        }

        $ctx->setMime($mime);
    }
}

<?php

namespace CodePulse\MediaUpload\Pipeline;

use CodePulse\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse\MediaUpload\Support\MimeMap;
use CodePulse\MediaUpload\UploadContext;
use finfo;

/**
 * Detects the real type from magic bytes. Client Content-Type is ignored.
 */
final class SniffMimeType
{
    public function __construct(private readonly MimeMap $mimeMap)
    {
    }

    public function __invoke(UploadContext $ctx): void
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($ctx->path());

        if (! is_string($mime) || ! in_array($mime, $ctx->profile->mimes, true)) {
            throw Rejected::because(Rejected::MIME_NOT_ALLOWED, 'This file type is not allowed.');
        }

        if (! $this->mimeMap->allows($mime, $ctx->clientExtension())) {
            throw Rejected::because(Rejected::EXTENSION_MISMATCH, 'The file extension does not match its contents.');
        }

        $ctx->setMime($mime);
    }
}

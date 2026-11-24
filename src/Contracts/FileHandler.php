<?php

namespace CodePulse\MediaUpload\Contracts;

use CodePulse\MediaUpload\UploadContext;

/**
 * Type specific processing, chosen by the sniffed MIME type.
 */
interface FileHandler
{
    /** Inspect / decode / sanitise the source. Throw RejectedUploadException to refuse. */
    public function decode(UploadContext $ctx): void;

    /** Produce the final output via $ctx->setOutput(). */
    public function encode(UploadContext $ctx): void;
}

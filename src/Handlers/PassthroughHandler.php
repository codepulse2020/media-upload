<?php

namespace CodePulse\MediaUpload\Handlers;

use CodePulse\MediaUpload\Contracts\FileHandler;
use CodePulse\MediaUpload\UploadContext;

/**
 * Stores whitelisted, non-transformable types as they are. Such files are
 * always served as attachments (see serve.inline_mimes).
 */
final class PassthroughHandler implements FileHandler
{
    public function decode(UploadContext $ctx): void
    {
    }

    public function encode(UploadContext $ctx): void
    {
        // The client's extension is already verified against the sniffed MIME.
        $ctx->setOutput(null, (string) $ctx->mime(), $ctx->clientExtension());
    }
}

<?php

namespace CodePulse\MediaUpload\Pipeline;

use CodePulse\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse\MediaUpload\Support\FilenameSanitizer;
use CodePulse\MediaUpload\Support\MimeMap;
use CodePulse\MediaUpload\UploadContext;
use Illuminate\Http\UploadedFile;

/**
 * Cheap checks that only look at metadata: upload status, size and name.
 *
 * @internal
 */
final class ValidateUpload
{
    /** @param list<string> $blockedExtensions */
    public function __construct(
        private readonly MimeMap $mimeMap,
        private readonly array $blockedExtensions,
    ) {
    }

    public function __invoke(UploadContext $ctx): void
    {
        $source = $ctx->source;

        if ($source instanceof UploadedFile && ! $source->isValid()) {
            throw Rejected::because(Rejected::UPLOAD_FAILED, 'The file failed to upload.');
        }

        if (! is_file($ctx->path()) || ! is_readable($ctx->path())) {
            throw Rejected::because(Rejected::UPLOAD_FAILED, 'The uploaded file is not readable.');
        }

        $size = $ctx->size();

        if ($size <= 0) {
            throw Rejected::because(Rejected::EMPTY_FILE, 'The file is empty.');
        }

        if ($size > $ctx->profile->maxBytes()) {
            throw Rejected::because(Rejected::TOO_LARGE, "The file may not be larger than {$ctx->profile->maxSizeKb} KB.");
        }

        $extension = $ctx->clientExtension();

        if ($extension === '') {
            throw Rejected::because(Rejected::EXTENSION_MISSING, 'The file must have an extension.');
        }

        // Check every segment so "shell.php.jpg" and "x.phtml.png" are refused.
        foreach (FilenameSanitizer::extensionSegments($ctx->clientName) as $segment) {
            if (in_array($segment, $this->blockedExtensions, true)) {
                throw Rejected::because(Rejected::BLOCKED_EXTENSION, 'This file type is not allowed.');
            }
        }

        if (! in_array($extension, $this->mimeMap->extensionsForAll($ctx->profile->mimes), true)) {
            throw Rejected::because(Rejected::EXTENSION_NOT_ALLOWED, 'This file type is not allowed.');
        }
    }
}

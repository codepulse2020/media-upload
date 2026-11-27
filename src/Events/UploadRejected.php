<?php

namespace CodePulse2020\MediaUpload\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** @api */
final class UploadRejected
{
    use Dispatchable;

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly string $profile,
        public readonly string $originalName,
        public readonly array $context = [],
        public readonly ?string $id = null,
    ) {
    }
}

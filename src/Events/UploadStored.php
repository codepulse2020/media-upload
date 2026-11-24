<?php

namespace CodePulse\MediaUpload\Events;

use CodePulse\MediaUpload\StoredFile;
use Illuminate\Foundation\Events\Dispatchable;

/** @api */
final class UploadStored
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context  data passed with withContext()
     * @param  string|null  $id  PendingUpload id for queued uploads
     */
    public function __construct(
        public readonly StoredFile $file,
        public readonly array $context = [],
        public readonly ?string $id = null,
    ) {
    }
}

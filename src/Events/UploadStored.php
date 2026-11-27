<?php

namespace CodePulse2020\MediaUpload\Events;

use CodePulse2020\MediaUpload\StoredFile;
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

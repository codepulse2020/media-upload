<?php

namespace CodePulse\MediaUpload;

/**
 * Returned by storeAsync(). Listen for UploadStored / UploadRejected and match
 * them by $id (also available as $event->id).
 *
 * @api
 */
final class PendingUpload
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $id,
        public readonly string $profile,
        public readonly string $originalName,
        public readonly array $context = [],
    ) {
    }
}

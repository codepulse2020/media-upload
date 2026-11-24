<?php

namespace CodePulse\MediaUpload\Facades;

use CodePulse\MediaUpload\MediaUploader;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \CodePulse\MediaUpload\UploadBuilder profile(?string $name = null)
 * @method static \CodePulse\MediaUpload\StoredFile store(\SplFileInfo $file, ?string $profile = null)
 * @method static \CodePulse\MediaUpload\PendingUpload storeAsync(\SplFileInfo $file, ?string $profile = null)
 * @method static \CodePulse\MediaUpload\MediaUploader extend(string $point, mixed $hook)
 * @method static string url(\CodePulse\MediaUpload\StoredFile|string $file, ?int $minutes = null, ?string $disk = null, ?string $downloadName = null)
 *
 * @see MediaUploader
 */
final class MediaUpload extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaUploader::class;
    }
}

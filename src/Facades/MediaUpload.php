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
 * @method static \Illuminate\Routing\Route routes(?string $uri = null)
 * @method static string publicUrl(string $path, ?string $disk = null)
 * @method static \CodePulse\MediaUpload\UploadRules rules(?string $profile = null)
 * @method static string token(\CodePulse\MediaUpload\StoredFile $file, ?int $ttlMinutes = null)
 * @method static \CodePulse\MediaUpload\StoredFile fromToken(string $token, ?string $profile = null, string|int|null $owner = null)
 * @method static \CodePulse\MediaUpload\StoredFile claim(string $token, ?string $profile = null, string|int|null $owner = null)
 * @method static bool delete(\CodePulse\MediaUpload\StoredFile|string $file, ?string $disk = null, bool $force = false)
 *
 * @api
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

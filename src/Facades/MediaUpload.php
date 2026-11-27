<?php

namespace CodePulse2020\MediaUpload\Facades;

use CodePulse2020\MediaUpload\MediaUploader;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \CodePulse2020\MediaUpload\UploadBuilder profile(?string $name = null)
 * @method static \CodePulse2020\MediaUpload\StoredFile store(\SplFileInfo $file, ?string $profile = null)
 * @method static \CodePulse2020\MediaUpload\PendingUpload storeAsync(\SplFileInfo $file, ?string $profile = null)
 * @method static \CodePulse2020\MediaUpload\MediaUploader extend(string $point, mixed $hook)
 * @method static string url(\CodePulse2020\MediaUpload\StoredFile|string $file, ?int $minutes = null, ?string $disk = null, ?string $downloadName = null)
 * @method static \Illuminate\Routing\Route routes(?string $uri = null)
 * @method static string publicUrl(string $path, ?string $disk = null)
 * @method static \CodePulse2020\MediaUpload\UploadRules rules(?string $profile = null)
 * @method static string token(\CodePulse2020\MediaUpload\StoredFile $file, ?int $ttlMinutes = null)
 * @method static \CodePulse2020\MediaUpload\StoredFile fromToken(string $token, ?string $profile = null, string|int|null $owner = null)
 * @method static \CodePulse2020\MediaUpload\StoredFile claim(string $token, ?string $profile = null, string|int|null $owner = null)
 * @method static bool delete(\CodePulse2020\MediaUpload\StoredFile|string $file, ?string $disk = null, bool $force = false)
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

<?php

namespace CodePulse\MediaUpload;

use Closure;
use CodePulse\MediaUpload\Contracts\ImageTransformer;
use CodePulse\MediaUpload\Contracts\Stage;
use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Pipeline\HookRunner;
use CodePulse\MediaUpload\Support\FilenameSanitizer;
use CodePulse\MediaUpload\Support\ServePolicy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use SplFileInfo;

final class MediaUploader
{
    /** @var array<string, list<mixed>> hooks registered with extend() */
    private array $extensions = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $repository,
    ) {
    }

    public function profile(?string $name = null): UploadBuilder
    {
        $config = $this->config();
        $name ??= $config['default_profile'] ?? 'image';

        return new UploadBuilder($this, Profile::fromConfig($name, $config));
    }

    public function store(UploadedFile|SplFileInfo $file, ?string $profile = null): StoredFile
    {
        return $this->profile($profile)->store($file);
    }

    public function storeAsync(UploadedFile|SplFileInfo $file, ?string $profile = null): PendingUpload
    {
        return $this->profile($profile)->storeAsync($file);
    }

    /**
     * Register a hook for every profile, e.g. in AppServiceProvider::boot():
     *   MediaUpload::extend('validated', CheckQuota::class);
     */
    public function extend(string $point, Stage|ImageTransformer|Closure|string|array $hook): self
    {
        if (! in_array($point, HookRunner::POINTS, true)) {
            throw new InvalidConfigException("Unknown hook point [{$point}]. Valid: ".implode(', ', HookRunner::POINTS).'.');
        }

        $this->extensions[$point][] = $hook;

        return $this;
    }

    /** @return list<mixed> */
    public function extensions(string $point): array
    {
        return $this->extensions[$point] ?? [];
    }

    /**
     * Temporary URL for a stored file.
     *
     * serve.mode "proxy": signed route through ServeFileController (all hardened headers).
     * serve.mode "presigned": S3 presigned GET with Content-Type / Content-Disposition /
     * Cache-Control overrides. Disks that cannot presign fall back to the proxy route.
     */
    public function url(StoredFile|string $file, ?int $minutes = null, ?string $disk = null, ?string $downloadName = null): string
    {
        $serve = $this->config('serve') ?? [];
        $policy = $this->container->make(ServePolicy::class);

        $path = $file instanceof StoredFile ? $file->path : $file;
        $disk ??= $file instanceof StoredFile ? $file->disk : ($this->config('disk') ?? 'local');
        $mime = $file instanceof StoredFile ? $file->mime : $policy->mimeForPath($path);
        $profileDisposition = $file instanceof StoredFile ? $this->config("profiles.{$file->profile}.disposition") : null;
        $downloadName ??= $file instanceof StoredFile
            ? $policy->downloadName($file->originalName, $file->extension)
            : basename($path);

        $ttl = 60 * ($minutes ?? (int) ($serve['url_ttl'] ?? 30));
        $expires = now()->addSeconds($ttl);
        $storage = $this->container->make('filesystem')->disk($disk);

        if (($serve['mode'] ?? 'proxy') === 'presigned' && $storage instanceof AwsS3V3Adapter) {
            return $storage->temporaryUrl($path, $expires, [
                'ResponseContentType' => $mime,
                'ResponseContentDisposition' => $policy->disposition($mime, $downloadName, $profileDisposition),
                'ResponseCacheControl' => $policy->cacheControl($ttl),
            ]);
        }

        if (! ($serve['enabled'] ?? true)) {
            throw new InvalidConfigException('The serve route is disabled (media-upload.serve.enabled).');
        }

        $parameters = ['path' => $path, 'disk' => $disk, 'name' => FilenameSanitizer::sanitize($downloadName)];

        // Part of the signature, so the attachment policy cannot be removed from the URL.
        if (! $policy->isInline($mime, $profileDisposition)) {
            $parameters['download'] = 1;
        }

        return URL::temporarySignedRoute($serve['name'] ?? 'media-upload.serve', $expires, $parameters);
    }

    public function processor(): UploadProcessor
    {
        return $this->container->make(UploadProcessor::class);
    }

    public function config(?string $key = null): mixed
    {
        $config = (array) $this->repository->get('media-upload', []);

        return $key === null ? $config : data_get($config, $key);
    }
}

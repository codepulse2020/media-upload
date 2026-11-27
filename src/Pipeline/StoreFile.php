<?php

namespace CodePulse2020\MediaUpload\Pipeline;

use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse2020\MediaUpload\StoredFile;
use CodePulse2020\MediaUpload\Support\DiskGuard;
use CodePulse2020\MediaUpload\Support\Locks;
use CodePulse2020\MediaUpload\Support\ServePolicy;
use CodePulse2020\MediaUpload\Support\StorageWriter;
use CodePulse2020\MediaUpload\UploadContext;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Str;

/** @internal */
final class StoreFile
{
    /** @param array<string, mixed> $diskConfigs config('filesystems.disks') */
    public function __construct(
        private readonly FilesystemFactory $filesystem,
        private readonly array $diskConfigs,
        private readonly ServePolicy $serve,
        private readonly ?CacheFactory $cache = null,
        private readonly string $generator = 'ulid',
        private readonly ?string $publicCacheControl = null,
        private readonly int $lockWait = 10,
    ) {
    }

    public function __invoke(UploadContext $ctx): StoredFile
    {
        $profile = $ctx->profile;

        if (! $profile->isPublic()) {
            DiskGuard::assertPrivate($profile->disk, $this->diskConfigs);
        }

        $hookChoseName = $ctx->filename !== null;
        $extension = (string) $ctx->outputExtension();
        $target = ltrim($this->directory($ctx).'/'.$this->filename($ctx).'.'.$extension, '/');
        $dedupe = ! $hookChoseName && $this->generator === 'hash';

        if ($ctx->isPending()) {
            return $this->storePending($ctx, $target, $extension, $dedupe);
        }

        // Random ids cannot collide; deterministic names (content hash or a
        // hook supplied name) are written under a lock so concurrent uploads
        // cannot both pass the existence check and overwrite each other.
        $write = fn () => $this->write($ctx, $profile->disk, $target, $extension, $dedupe, $profile->visibility);

        return $hookChoseName || $dedupe
            ? Locks::run($this->cache, Locks::forTarget($profile->disk, $target), $write, $this->lockWait)
            : $write();
    }

    /**
     * Pending uploads always wait on the private disk under a random name; the
     * final location is decided now and applied by claim().
     */
    private function storePending(UploadContext $ctx, string $target, string $extension, bool $dedupe): StoredFile
    {
        $profile = $ctx->profile;
        DiskGuard::assertPrivate($profile->pendingDisk, $this->diskConfigs);

        if (! $dedupe && $ctx->filename !== null && $this->filesystem->disk($profile->disk)->exists($target)) {
            throw Rejected::because(Rejected::PATH_CONFLICT, 'A file with this name already exists.');
        }

        $path = $profile->pendingDirectory.'/'.strtolower((string) Str::ulid()).'.'.$extension;
        $stored = $this->write($ctx, $profile->pendingDisk, $path, $extension, false, 'private');

        $stored = $stored->with(['pending_target' => [
            'disk' => $profile->disk,
            'path' => $target,
            'visibility' => $profile->visibility,
        ]]);

        $ctx->setStored($stored, true);

        return $stored;
    }

    private function write(UploadContext $ctx, string $diskName, string $path, string $extension, bool $dedupe, string $visibility): StoredFile
    {
        $disk = $this->filesystem->disk($diskName);
        $mime = (string) $ctx->outputMime();
        $created = true;

        if ($disk->exists($path)) {
            if (! $dedupe) {
                throw Rejected::because(Rejected::PATH_CONFLICT, 'A file with this name already exists.');
            }

            // Content addressed: the existing file has exactly these bytes.
            $created = false;
        } else {
            $stream = $ctx->outputStream();

            try {
                StorageWriter::write(
                    $disk,
                    $path,
                    $stream,
                    $mime,
                    $this->serve->disposition(
                        $mime,
                        $this->serve->downloadName($ctx->originalName, $extension),
                        $ctx->profile->disposition,
                    ),
                    $visibility,
                    $visibility === 'public' ? $this->publicCacheControl : 'private, no-store',
                );
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $stored = new StoredFile(
            disk: $diskName,
            path: $path,
            mime: $mime,
            extension: $extension,
            size: $ctx->outputSize(),
            sha256: $ctx->outputHash(),
            originalName: $ctx->originalName,
            profile: $ctx->profile->name,
            width: $ctx->width(),
            height: $ctx->height(),
            meta: $ctx->meta,
            visibility: $visibility,
            owner: $ctx->owner(),
        );

        $ctx->setStored($stored, $created);

        return $stored;
    }

    private function directory(UploadContext $ctx): string
    {
        $directory = $ctx->directory ?? $ctx->profile->directory.'/'.date('Y/m');
        $directory = trim(str_replace('\\', '/', $directory), '/');

        foreach (explode('/', $directory) as $segment) {
            // Only plain segments: no "", ".", "..", dots or other special characters.
            if (! preg_match('/^[A-Za-z0-9_\-]+$/', $segment)) {
                throw new InvalidConfigException("Invalid upload directory [{$directory}].");
            }
        }

        // Hooks cannot move a public upload out of the public prefix, or a private one into it.
        if ($ctx->directory !== null && ! $this->insideProfileArea($ctx, $directory)) {
            throw new InvalidConfigException("Directory [{$directory}] is outside the area allowed for profile [{$ctx->profile->name}].");
        }

        return $directory;
    }

    private function insideProfileArea(UploadContext $ctx, string $directory): bool
    {
        $public = $this->serve->publicDirectory();
        $inPublic = $public !== '' && ($directory === $public || str_starts_with($directory, $public.'/'));

        return $ctx->profile->isPublic() ? $inPublic : ! $inPublic;
    }

    private function filename(UploadContext $ctx): string
    {
        if ($ctx->filename !== null) {
            // Hooks may choose the name but never the extension.
            if (! preg_match('/^[A-Za-z0-9_\-]{1,150}$/', $ctx->filename)) {
                throw new InvalidConfigException("Invalid upload file name [{$ctx->filename}]. Use [A-Za-z0-9_-] without extension.");
            }

            return $ctx->filename;
        }

        return match ($this->generator) {
            'uuid' => (string) Str::uuid(),
            'hash' => $ctx->outputHash(),
            default => strtolower((string) Str::ulid()),
        };
    }
}

<?php

namespace CodePulse\MediaUpload\Pipeline;

use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse\MediaUpload\StoredFile;
use CodePulse\MediaUpload\Support\DiskGuard;
use CodePulse\MediaUpload\Support\ServePolicy;
use CodePulse\MediaUpload\Support\StorageWriter;
use CodePulse\MediaUpload\UploadContext;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class StoreFile
{
    /** @param array<string, mixed> $diskConfigs config('filesystems.disks') */
    public function __construct(
        private readonly FilesystemFactory $filesystem,
        private readonly array $diskConfigs,
        private readonly ServePolicy $serve,
        private readonly ?CacheFactory $cache = null,
        private readonly string $generator = 'ulid',
    ) {
    }

    public function __invoke(UploadContext $ctx): StoredFile
    {
        $diskName = $ctx->profile->disk;
        DiskGuard::assertPrivate($diskName, $this->diskConfigs);

        $directory = $this->directory($ctx);
        $hookChoseName = $ctx->filename !== null;
        $filename = $this->filename($ctx);
        $extension = (string) $ctx->outputExtension();
        $path = ltrim($directory.'/'.$filename.'.'.$extension, '/');
        $disk = $this->filesystem->disk($diskName);

        // Random ids cannot collide; deterministic names (content hash or a
        // hook supplied name) are written under a lock so concurrent uploads
        // cannot both pass the existence check and overwrite each other.
        $deterministic = $hookChoseName || $this->generator === 'hash';
        $write = fn () => $this->write($ctx, $disk, $diskName, $path, $extension, dedupe: ! $hookChoseName && $this->generator === 'hash');

        return $deterministic ? $this->locked($diskName.':'.$path, $write) : $write();
    }

    private function write(UploadContext $ctx, Filesystem $disk, string $diskName, string $path, string $extension, bool $dedupe): StoredFile
    {
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
        );

        $ctx->setStored($stored, $created);

        return $stored;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(string $key, callable $callback): mixed
    {
        $store = $this->cache?->store();

        if ($store === null || ! $store->getStore() instanceof LockProvider) {
            return $callback();
        }

        return $store->getStore()->lock('media-upload:'.sha1($key), 30)->block(10, $callback);
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

        return $directory;
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

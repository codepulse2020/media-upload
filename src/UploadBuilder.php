<?php

namespace CodePulse\MediaUpload;

use Closure;
use CodePulse\MediaUpload\Contracts\ImageTransformer;
use CodePulse\MediaUpload\Contracts\Stage;
use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Exceptions\NonSerializableHookException;
use CodePulse\MediaUpload\Jobs\ProcessQuarantinedUpload;
use CodePulse\MediaUpload\Pipeline\HookRunner;
use CodePulse\MediaUpload\Support\DiskGuard;
use CodePulse\MediaUpload\Support\HookResolver;
use CodePulse\MediaUpload\Support\SourceNormalizer;
use CodePulse\MediaUpload\Support\StorageWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use SplFileInfo;

/**
 * Fluent, per-call configuration:
 *
 *   MediaUpload::profile('product')
 *       ->transform(new Watermark(text: $shop->name))
 *       ->hook('before_store', new PutUnderShop($shop))
 *       ->withContext(['shop_id' => $shop->id])
 *       ->store($request->file('photo'));
 *
 * @api
 */
final class UploadBuilder
{
    /** @var array<string, list<mixed>> */
    private array $hooks = [];

    private string|int|null $owner = null;

    private bool $pending = false;

    /** @var array<string, mixed> */
    private array $context = [];

    public function __construct(
        private readonly MediaUploader $uploader,
        private readonly Profile $profile,
    ) {
    }

    public function transform(ImageTransformer|Closure|string|array ...$transformers): self
    {
        foreach ($transformers as $transformer) {
            $this->hook(HookRunner::TRANSFORM, $transformer);
        }

        return $this;
    }

    public function hook(string $point, Stage|ImageTransformer|Closure|string|array $hook): self
    {
        if (! in_array($point, HookRunner::POINTS, true)) {
            throw new InvalidConfigException("Unknown hook point [{$point}]. Valid: ".implode(', ', HookRunner::POINTS).'.');
        }

        $this->hooks[$point][] = $hook;

        return $this;
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): self
    {
        $this->context = array_replace($this->context, $context);

        return $this;
    }

    /**
     * Records who uploaded the file. It is carried in StoredFile::$owner and
     * in tokens, and fromToken()/claim() then require the same owner.
     */
    public function ownedBy(string|int $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * Two-step flows: store in the pending area (always private) and let the
     * caller hand out $file->token(); MediaUpload::claim($token) later moves
     * it to its final location. Unclaimed files are removed by
     * `php artisan media-upload:prune`.
     */
    public function pending(bool $pending = true): self
    {
        $this->pending = $pending;

        return $this;
    }

    /**
     * Validate, process and store synchronously.
     *
     * Accepts local files and remote ones exposing readStream() (e.g. Livewire
     * temporary uploads on an S3 disk); remote files are copied locally first,
     * stopping as soon as the profile's size limit is exceeded.
     *
     * @throws Exceptions\RejectedUploadException
     */
    public function store(UploadedFile|SplFileInfo $file, ?string $originalName = null): StoredFile
    {
        $originalName = $this->originalName($file, $originalName);
        $sources = new SourceNormalizer();

        try {
            $processor = $this->uploader->processor();
            $ctx = $this->makeContext($sources->normalize($file, $this->profile->maxBytes()), $originalName);

            return $processor->process($ctx, $this->allHooks());
        } finally {
            $sources->cleanup();
        }
    }

    /**
     * Store from a stream, e.g. a file fetched from another service.
     *
     * @param  resource  $stream
     *
     * @throws Exceptions\RejectedUploadException
     */
    public function storeFromStream($stream, string $originalName): StoredFile
    {
        $sources = new SourceNormalizer();

        try {
            return $this->store($sources->fromStream($stream, $this->profile->maxBytes()), $originalName);
        } finally {
            $sources->cleanup();
        }
    }

    /**
     * Run an existing object (e.g. one uploaded directly to S3) through the
     * pipeline. The source object is left untouched.
     *
     * @throws Exceptions\RejectedUploadException
     */
    public function storeFromDisk(string $disk, string $path, ?string $originalName = null): StoredFile
    {
        $stream = app('filesystem')->disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw Exceptions\RejectedUploadException::because(Exceptions\RejectedUploadException::UPLOAD_FAILED, 'The source file is not readable.');
        }

        try {
            return $this->storeFromStream($stream, $originalName ?? basename($path));
        } finally {
            is_resource($stream) && fclose($stream);
        }
    }

    /**
     * Run the cheap checks now, park the file in quarantine and finish on the queue.
     * Listen for Events\UploadStored / Events\UploadRejected to get the result.
     *
     * @throws Exceptions\RejectedUploadException
     */
    public function storeAsync(UploadedFile|SplFileInfo $file, ?string $originalName = null): PendingUpload
    {
        $hooks = $this->allHooks();
        HookResolver::assertSerializable($hooks);

        $originalName = $this->originalName($file, $originalName);
        $sources = new SourceNormalizer();

        try {
            $local = $sources->normalize($file, $this->profile->maxBytes());
            $this->uploader->processor()->precheck($this->makeContext($local, $originalName));

            return $this->quarantine($local, $originalName, $hooks);
        } finally {
            $sources->cleanup();
        }
    }

    /**
     * Cheap checks only (size, extension, real MIME). Does not store anything.
     *
     * @throws Exceptions\RejectedUploadException
     */
    public function validate(UploadedFile|SplFileInfo $file, ?string $originalName = null): void
    {
        $originalName = $this->originalName($file, $originalName);
        $sources = new SourceNormalizer();

        try {
            $this->uploader->processor()->precheck(
                $this->makeContext($sources->normalize($file, $this->profile->maxBytes()), $originalName)
            );
        } finally {
            $sources->cleanup();
        }
    }

    /** @param array<string, list<mixed>> $hooks */
    private function quarantine(SplFileInfo $local, string $originalName, array $hooks): PendingUpload
    {
        $config = $this->uploader->config('queue');
        $disk = $config['quarantine_disk'] ?? 'local';
        DiskGuard::assertPrivate($disk, config('filesystems.disks', []));

        $id = strtolower((string) Str::ulid());
        $path = trim($config['quarantine_dir'] ?? 'quarantine', '/').'/'.$id;

        $job = new ProcessQuarantinedUpload(
            $id, $this->profile->name, $disk, $path, $originalName, $hooks, $this->context, $this->owner, $this->pending,
        );

        // Fail before anything is written if the job payload cannot be queued.
        try {
            serialize($job);
        } catch (\Throwable $e) {
            throw new NonSerializableHookException('The queued upload cannot be serialized: '.$e->getMessage(), 0, $e);
        }

        $storage = app('filesystem')->disk($disk);
        $stream = fopen($local->getRealPath() ?: $local->getPathname(), 'rb');

        try {
            StorageWriter::write($storage, $path, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        try {
            app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($job->onConnection($config['connection'] ?? null)->onQueue($config['queue'] ?? null));
        } catch (\Throwable $e) {
            $storage->delete($path);

            throw $e;
        }

        return new PendingUpload($id, $this->profile->name, $originalName, $this->context);
    }

    private function makeContext(SplFileInfo $file, string $originalName): UploadContext
    {
        $ctx = $this->uploader->processor()->makeContext($this->profile, $file, $originalName, $this->context);
        $ctx->setOwner($this->owner);
        $ctx->setPending($this->pending);

        return $ctx;
    }

    /** @return array<string, list<mixed>> */
    private function allHooks(): array
    {
        $hooks = [];

        foreach (HookRunner::POINTS as $point) {
            $hooks[$point] = array_merge($this->uploader->extensions($point), $this->hooks[$point] ?? []);
        }

        return $hooks;
    }

    private function originalName(SplFileInfo $file, ?string $originalName): string
    {
        return $originalName
            ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename());
    }
}

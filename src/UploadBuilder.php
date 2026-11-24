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
 */
final class UploadBuilder
{
    /** @var array<string, list<mixed>> */
    private array $hooks = [];

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
     * Validate, process and store synchronously.
     *
     * @throws Exceptions\RejectedUploadException
     */
    public function store(UploadedFile|SplFileInfo $file, ?string $originalName = null): StoredFile
    {
        $processor = $this->uploader->processor();
        $ctx = $processor->makeContext($this->profile, $file, $this->originalName($file, $originalName), $this->context);

        return $processor->process($ctx, $this->allHooks());
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

        $processor = $this->uploader->processor();
        $originalName = $this->originalName($file, $originalName);
        $processor->precheck($processor->makeContext($this->profile, $file, $originalName, $this->context));

        $config = $this->uploader->config('queue');
        $disk = $config['quarantine_disk'] ?? 'local';
        DiskGuard::assertPrivate($disk, config('filesystems.disks', []));

        $id = strtolower((string) Str::ulid());
        $path = trim($config['quarantine_dir'] ?? 'quarantine', '/').'/'.$id;

        $job = new ProcessQuarantinedUpload($id, $this->profile->name, $disk, $path, $originalName, $hooks, $this->context);

        // Fail before anything is written if the job payload cannot be queued.
        try {
            serialize($job);
        } catch (\Throwable $e) {
            throw new NonSerializableHookException('The queued upload cannot be serialized: '.$e->getMessage(), 0, $e);
        }

        $storage = app('filesystem')->disk($disk);
        $stream = fopen($file->getRealPath() ?: $file->getPathname(), 'rb');

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

    /**
     * Cheap checks only (size, extension, real MIME). Does not store anything.
     *
     * @throws Exceptions\RejectedUploadException
     */
    public function validate(UploadedFile|SplFileInfo $file, ?string $originalName = null): void
    {
        $processor = $this->uploader->processor();
        $processor->precheck($processor->makeContext($this->profile, $file, $this->originalName($file, $originalName), $this->context));
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

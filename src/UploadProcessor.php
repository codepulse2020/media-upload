<?php

namespace CodePulse\MediaUpload;

use CodePulse\MediaUpload\Contracts\FileHandler;
use CodePulse\MediaUpload\Events\UploadRejected;
use CodePulse\MediaUpload\Events\UploadStored;
use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse\MediaUpload\Pipeline\HookRunner;
use CodePulse\MediaUpload\Pipeline\ScanVirus;
use CodePulse\MediaUpload\Pipeline\SniffMimeType;
use CodePulse\MediaUpload\Pipeline\StoreFile;
use CodePulse\MediaUpload\Pipeline\ValidateUpload;
use CodePulse\MediaUpload\Support\FilenameSanitizer;
use CodePulse\MediaUpload\Transformers\Fit;
use CodePulse\MediaUpload\Transformers\Resize;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use SplFileInfo;

/**
 * Runs the fixed, security critical sequence and the hook points between it:
 *
 *   validate → sniff → [validated] → decode → [transform] → encode → scan
 *   → [before_store] → store → [after_store]
 *
 * @internal
 */
final class UploadProcessor
{
    public function __construct(
        private readonly Container $container,
        private readonly ValidateUpload $validate,
        private readonly SniffMimeType $sniff,
        private readonly ScanVirus $scan,
        private readonly StoreFile $store,
        private readonly HookRunner $hooks,
        private readonly Dispatcher $events,
        private readonly FilesystemFactory $filesystem,
        /** @var array<string, class-string<FileHandler>> */
        private readonly array $handlers,
        private readonly int $maxOriginalNameLength = 200,
    ) {
    }

    public function makeContext(Profile $profile, SplFileInfo $file, string $originalName, array $context = []): UploadContext
    {
        return new UploadContext(
            $file,
            FilenameSanitizer::sanitize($originalName, $this->maxOriginalNameLength),
            $profile,
            $context,
            // Validation sees the whole name, so truncation cannot hide "x.php.….jpg".
            FilenameSanitizer::sanitize($originalName, null),
        );
    }

    /** Cheap checks only (size, name, real MIME). Used by the validation rule and before queueing. */
    public function precheck(UploadContext $ctx): void
    {
        ($this->validate)($ctx);
        ($this->sniff)($ctx);
    }

    /** @param array<string, list<mixed>> $extraHooks extend() + runtime hooks, per point */
    public function process(UploadContext $ctx, array $extraHooks = [], ?string $id = null): StoredFile
    {
        try {
            $stored = $this->run($ctx, $extraHooks);
        } catch (Rejected $e) {
            $this->events->dispatch(new UploadRejected(
                $e->reason, $e->getMessage(), $ctx->profile->name, $ctx->originalName, $ctx->context, $id,
            ));

            throw $e;
        }

        $this->events->dispatch(new UploadStored($stored, $ctx->context, $id));

        return $stored;
    }

    /**
     * Runs the whole sequence without dispatching events (the queue job uses
     * this to control retries and event delivery itself).
     *
     * @param  array<string, list<mixed>>  $extraHooks
     */
    public function run(UploadContext $ctx, array $extraHooks = []): StoredFile
    {
        $hooks = fn (string $point) => array_merge($ctx->profile->hooksFor($point), $extraHooks[$point] ?? []);

        $this->precheck($ctx);
        $sniffed = $ctx->mime();

        $this->hooks->run(HookRunner::VALIDATED, $hooks(HookRunner::VALIDATED), $ctx);
        $this->assertMimeUnchanged($ctx, $sniffed, HookRunner::VALIDATED);

        $handler = $this->handlerFor((string) $ctx->mime());
        $handler->decode($ctx);

        if ($ctx->isImage()) {
            $this->hooks->run(
                HookRunner::TRANSFORM,
                array_merge($this->profileTransformers($ctx->profile), $hooks(HookRunner::TRANSFORM)),
                $ctx,
            );
            $this->assertMimeUnchanged($ctx, $sniffed, HookRunner::TRANSFORM);
        }

        $handler->encode($ctx);

        if ($ctx->outputMime() === null || $ctx->outputExtension() === null) {
            throw new \LogicException($handler::class.'::encode() did not set an output.');
        }

        ($this->scan)($ctx);

        $beforeStore = $hooks(HookRunner::BEFORE_STORE);

        if ($beforeStore !== []) {
            $fingerprint = $ctx->fingerprint();
            $this->hooks->run(HookRunner::BEFORE_STORE, $beforeStore, $ctx);

            if ($ctx->fingerprint() !== $fingerprint) {
                throw Rejected::because(Rejected::HOOK_TAMPERED, 'A before_store hook modified the processed file.');
            }
        }

        $stored = ($this->store)($ctx);

        try {
            $this->hooks->run(HookRunner::AFTER_STORE, $hooks(HookRunner::AFTER_STORE), $ctx);
        } catch (\Throwable $e) {
            // Do not leave orphans behind when a post-processing hook fails, but
            // never delete a deduplicated file that other uploads already point to.
            if ($ctx->createdFile()) {
                $this->filesystem->disk($stored->disk)->delete($stored->path);
            }

            throw $e;
        }

        // after_store hooks may add meta (e.g. thumbnail paths).
        return $stored->withMeta($ctx->meta);
    }

    private function assertMimeUnchanged(UploadContext $ctx, ?string $sniffed, string $point): void
    {
        if ($ctx->mime() !== $sniffed) {
            throw Rejected::because(Rejected::HOOK_TAMPERED, "A [{$point}] hook changed the detected file type.");
        }
    }

    private function handlerFor(string $mime): FileHandler
    {
        $class = $this->handlers[$mime] ?? $this->handlers['*'] ?? null;

        if ($class === null) {
            throw new InvalidConfigException("No handler configured for [{$mime}].");
        }

        $handler = $this->container->make($class);

        if (! $handler instanceof FileHandler) {
            throw new InvalidConfigException("Handler [{$class}] must implement ".FileHandler::class.'.');
        }

        return $handler;
    }

    /** @return list<Contracts\ImageTransformer> */
    private function profileTransformers(Profile $profile): array
    {
        $resize = $profile->image['resize'] ?? null;

        if (! is_array($resize)) {
            return [];
        }

        $width = isset($resize['width']) ? (int) $resize['width'] : null;
        $height = isset($resize['height']) ? (int) $resize['height'] : null;

        return match ($resize['mode'] ?? 'contain') {
            'cover' => [new Fit((int) $width, (int) $height, $resize['position'] ?? 'center')],
            'contain' => [new Resize($width, $height)],
            default => throw new InvalidConfigException("Unknown resize mode [{$resize['mode']}] in profile [{$profile->name}]."),
        };
    }
}

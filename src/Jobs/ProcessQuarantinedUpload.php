<?php

namespace CodePulse2020\MediaUpload\Jobs;

use CodePulse2020\MediaUpload\Events\UploadRejected;
use CodePulse2020\MediaUpload\Events\UploadStored;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse2020\MediaUpload\MediaUploader;
use CodePulse2020\MediaUpload\Profile;
use CodePulse2020\MediaUpload\StoredFile;
use CodePulse2020\MediaUpload\Support\StorageWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Finishes a storeAsync() upload. Idempotent per upload id:
 *
 *   quarantine/{id}         raw upload, deleted once processed
 *   quarantine/{id}.stored  result marker, written right after storing
 *
 * A retry after a successful store (e.g. an UploadStored listener threw)
 * re-delivers the event from the marker instead of storing another copy.
 *
 * @internal dispatched by UploadBuilder::storeAsync()
 */
final class ProcessQuarantinedUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> seconds between attempts */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, list<mixed>>  $hooks
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $id,
        public readonly string $profile,
        public readonly string $disk,
        public readonly string $path,
        public readonly string $originalName,
        public readonly array $hooks,
        public readonly array $context,
        public readonly string|int|null $owner = null,
        public readonly bool $pending = false,
        public readonly ?string $subdirectory = null,
    ) {
    }

    public function handle(MediaUploader $uploader): void
    {
        $storage = app('filesystem')->disk($this->disk);

        if ($storage->exists($this->marker())) {
            $this->deliver($this->storedFromMarker());

            return;
        }

        if (! $storage->exists($this->path)) {
            return; // Already handled by an earlier attempt.
        }

        $temp = tempnam(sys_get_temp_dir(), 'media-upload-');

        try {
            $in = $storage->readStream($this->path);
            $out = fopen($temp, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $processor = $uploader->processor();
            $profile = Profile::fromConfig($this->profile, $uploader->config());
            $ctx = $processor->makeContext($profile, new File($temp), $this->originalName, $this->context);
            $ctx->setOwner($this->owner);
            $ctx->setPending($this->pending);
            $ctx->setSubdirectory($this->subdirectory);

            try {
                $stored = $processor->run($ctx, $this->hooks);
            } catch (RejectedUploadException $e) {
                // A scanner outage is temporary: keep the file and try again.
                if ($e->reason === RejectedUploadException::SCAN_UNAVAILABLE && $this->attempts() < $this->tries) {
                    $this->release($this->backoff[$this->attempts() - 1] ?? end($this->backoff));

                    return;
                }

                $storage->delete($this->path);
                $this->reject($e->reason, $e->getMessage());

                return;
            }
        } finally {
            @unlink($temp);
        }

        $marker = fopen('php://temp', 'r+b');
        fwrite($marker, json_encode($stored->toArray(), JSON_THROW_ON_ERROR));
        rewind($marker);
        StorageWriter::write($storage, $this->marker(), $marker, 'application/json');
        fclose($marker);

        $storage->delete($this->path);
        $this->deliver($stored);
    }

    public function failed(\Throwable $e): void
    {
        $storage = app('filesystem')->disk($this->disk);

        // The file was stored; only event delivery failed. Do not report it as rejected.
        if ($storage->exists($this->marker())) {
            $storage->delete([$this->marker(), $this->path]);

            return;
        }

        $storage->delete($this->path);
        $this->reject(RejectedUploadException::PROCESSING_FAILED, 'The file could not be processed.');
    }

    private function deliver(StoredFile $stored): void
    {
        event(new UploadStored($stored, $this->context, $this->id));

        app('filesystem')->disk($this->disk)->delete($this->marker());
    }

    private function reject(string $reason, string $message): void
    {
        event(new UploadRejected($reason, $message, $this->profile, $this->originalName, $this->context, $this->id));
    }

    private function storedFromMarker(): StoredFile
    {
        $data = json_decode((string) app('filesystem')->disk($this->disk)->get($this->marker()), true, 512, JSON_THROW_ON_ERROR);

        return StoredFile::fromArray($data);
    }

    private function marker(): string
    {
        return $this->path.'.stored';
    }
}

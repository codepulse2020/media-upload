<?php

namespace CodePulse2020\MediaUpload\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Mimics Livewire's TemporaryUploadedFile on a remote (S3) disk: the
 * placeholder path is an empty local temp file, getRealPath() is the remote
 * key, and the contents are only reachable through readStream().
 */
final class RemoteUploadedFile extends UploadedFile
{
    /** @var resource keeps the empty placeholder file alive, like Livewire does */
    private $placeholder;

    public function __construct(private readonly string $disk, private readonly string $key, string $clientName)
    {
        $this->placeholder = tmpfile();
        parent::__construct(stream_get_meta_data($this->placeholder)['uri'], $clientName, null, null, true);
    }

    public function getRealPath(): string|false
    {
        return $this->key;
    }

    public function getSize(): int|false
    {
        return Storage::disk($this->disk)->size($this->key);
    }

    public function isValid(): bool
    {
        return true;
    }

    /** @return resource|null */
    public function readStream()
    {
        return Storage::disk($this->disk)->readStream($this->key);
    }
}

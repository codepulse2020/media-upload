<?php

namespace CodePulse\MediaUpload;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Result of a successful upload. The package does not persist anything:
 * save what you need (usually disk + path) in your own model.
 *
 * @implements Arrayable<string, mixed>
 */
final class StoredFile implements Arrayable, JsonSerializable
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $mime,
        public readonly string $extension,
        public readonly int $size,
        public readonly string $sha256,
        public readonly string $originalName,
        public readonly string $profile,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly array $meta = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            disk: $data['disk'],
            path: $data['path'],
            mime: $data['mime'],
            extension: $data['extension'],
            size: (int) $data['size'],
            sha256: $data['sha256'],
            originalName: $data['original_name'],
            profile: $data['profile'],
            width: $data['width'] ?? null,
            height: $data['height'] ?? null,
            meta: $data['meta'] ?? [],
        );
    }

    /** @param array<string, mixed> $meta */
    public function withMeta(array $meta): self
    {
        return new self(
            $this->disk, $this->path, $this->mime, $this->extension, $this->size, $this->sha256,
            $this->originalName, $this->profile, $this->width, $this->height, $meta,
        );
    }

    /** Temporary signed URL served with hardened headers. */
    public function url(?int $minutes = null): string
    {
        return app(MediaUploader::class)->url($this, $minutes);
    }

    public function toArray(): array
    {
        return [
            'disk' => $this->disk,
            'path' => $this->path,
            'mime' => $this->mime,
            'extension' => $this->extension,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'original_name' => $this->originalName,
            'profile' => $this->profile,
            'width' => $this->width,
            'height' => $this->height,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

<?php

namespace CodePulse2020\MediaUpload;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Result of a successful upload. The package does not persist anything:
 * save what you need (usually disk + path) in your own model.
 *
 * @api
 *
 * @implements Arrayable<string, mixed>
 */
final class StoredFile implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array{disk: string, path: string, visibility: string}|null  $pendingTarget
     *                                                                                      where claim() will move a pending upload
     */
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
        public readonly string $visibility = 'private',
        public readonly ?array $pendingTarget = null,
        public readonly string|int|null $owner = null,
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
            visibility: $data['visibility'] ?? 'private', // records saved before 1.0
            pendingTarget: $data['pending_target'] ?? null,
            owner: $data['owner'] ?? null,
        );
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    /** Waiting in the pending area until claim(). */
    public function isPending(): bool
    {
        return $this->pendingTarget !== null;
    }

    /** @param array<string, mixed> $meta */
    public function withMeta(array $meta): self
    {
        return $this->with(['meta' => $meta]);
    }

    /** @param array<string, mixed> $changes keys as in toArray() */
    public function with(array $changes): self
    {
        return self::fromArray(array_replace($this->toArray(), $changes));
    }

    /**
     * Public profiles: permanent URL. Private: temporary signed / presigned URL.
     */
    public function url(?int $minutes = null): string
    {
        return app(MediaUploader::class)->url($this, $minutes);
    }

    /**
     * Signed reference for two-step flows: return it to the client, accept it
     * back with Rules\UploadToken and resolve it with MediaUpload::fromToken()
     * or MediaUpload::claim().
     */
    public function token(?int $ttlMinutes = null): string
    {
        return app(MediaUploader::class)->token($this, $ttlMinutes);
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
            'visibility' => $this->visibility,
            'pending_target' => $this->pendingTarget,
            'owner' => $this->owner,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

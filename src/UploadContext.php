<?php

namespace CodePulse2020\MediaUpload;

use Intervention\Image\Interfaces\ImageInterface;
use SplFileInfo;

/**
 * Travels through every step of an upload. Hooks may read everything and may
 * change: image (transform), directory / filename (before_store) and meta.
 * The security relevant parts (sniffed MIME, output bytes) can only be set by
 * the core pipeline; tampering with them is detected and rejected.
 *
 * @api
 */
final class UploadContext
{
    /** Free form data from hooks; ends up in StoredFile::$meta. */
    public array $meta = [];

    /** Relative directory on the disk. Null = profile default. */
    public ?string $directory = null;

    /** File name without extension. Null = generated id. */
    public ?string $filename = null;

    private ?string $mime = null;

    private ?ImageInterface $image = null;

    private ?string $contents = null;

    private ?string $outputMime = null;

    private ?string $outputExtension = null;

    private ?int $width = null;

    private ?int $height = null;

    private ?StoredFile $stored = null;

    private bool $created = false;

    private bool $pending = false;

    private string|int|null $owner = null;

    private ?string $subdirectory = null;

    /**
     * @param  string  $originalName  sanitised and length-limited, for display / StoredFile
     * @param  array<string, mixed>  $context  data passed by the caller, e.g. ['user_id' => 1]
     * @param  string|null  $clientName  sanitised but full length, used for validation
     */
    public function __construct(
        public readonly SplFileInfo $source,
        public readonly string $originalName,
        public readonly Profile $profile,
        public readonly array $context = [],
        ?string $clientName = null,
    ) {
        $this->clientName = $clientName ?? $originalName;
    }

    /** Full (untruncated) sanitised client file name. Every extension segment is validated on it. */
    public readonly string $clientName;

    public function path(): string
    {
        return $this->source->getRealPath() ?: $this->source->getPathname();
    }

    public function clientExtension(): string
    {
        return Support\FilenameSanitizer::extension($this->clientName);
    }

    public function size(): int
    {
        return (int) $this->source->getSize();
    }

    /** MIME type detected from the file contents (not the client header). */
    public function mime(): ?string
    {
        return $this->mime;
    }

    public function isImage(): bool
    {
        return $this->image !== null;
    }

    public function image(): ?ImageInterface
    {
        return $this->image;
    }

    /** Used by ImageTransformer hooks through the transform hook runner. */
    public function replaceImage(ImageInterface $image): void
    {
        if ($this->image === null) {
            throw new \LogicException('This upload is not an image.');
        }

        $this->image = $image;
    }

    public function outputMime(): ?string
    {
        return $this->outputMime;
    }

    public function outputExtension(): ?string
    {
        return $this->outputExtension;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    /** Available in after_store hooks. */
    public function stored(): ?StoredFile
    {
        return $this->stored;
    }

    /** @return resource */
    public function outputStream()
    {
        if ($this->contents !== null) {
            $stream = fopen('php://temp', 'r+b');
            fwrite($stream, $this->contents);
            rewind($stream);

            return $stream;
        }

        $stream = fopen($this->path(), 'rb');

        if ($stream === false) {
            throw new \RuntimeException('Unable to open uploaded file.');
        }

        return $stream;
    }

    public function outputSize(): int
    {
        return $this->contents !== null ? strlen($this->contents) : $this->size();
    }

    public function outputHash(): string
    {
        return $this->contents !== null
            ? hash('sha256', $this->contents)
            : hash_file('sha256', $this->path());
    }

    /** @internal */
    public function fingerprint(): string
    {
        return implode('|', [$this->outputMime, $this->outputExtension, $this->outputHash()]);
    }

    /** @internal set by SniffMimeType */
    public function setMime(string $mime): void
    {
        $this->mime = $mime;
    }

    /** @internal set by image handlers */
    public function setImage(ImageInterface $image): void
    {
        $this->image = $image;
    }

    /**
     * @internal set by FileHandler::encode(). $contents null means "store the source as is".
     */
    public function setOutput(?string $contents, string $mime, string $extension, ?int $width = null, ?int $height = null): void
    {
        $this->contents = $contents;
        $this->outputMime = $mime;
        $this->outputExtension = strtolower($extension);
        $this->width = $width;
        $this->height = $height;
    }

    /** @internal $created is false when an identical, existing file was reused. */
    public function setStored(StoredFile $stored, bool $created = true): void
    {
        $this->stored = $stored;
        $this->created = $created;
    }

    /** True when the upload goes to the pending area and must be claim()ed. */
    public function isPending(): bool
    {
        return $this->pending;
    }

    /** Who uploaded the file (set with UploadBuilder::ownedBy()). */
    public function owner(): string|int|null
    {
        return $this->owner;
    }

    /** @internal */
    public function setOwner(string|int|null $owner): void
    {
        $this->owner = $owner;
    }

    /**
     * Sub directory inside the profile directory chosen by the caller
     * (UploadBuilder::subdirectory()), or null. Hooks cannot change it.
     */
    public function subdirectory(): ?string
    {
        return $this->subdirectory;
    }

    /** @internal set once by the builder or the queue job */
    public function setSubdirectory(?string $subdirectory): void
    {
        if ($this->subdirectory !== null) {
            throw new \LogicException('The upload sub directory is already set.');
        }

        $this->subdirectory = $subdirectory;
    }

    /** @internal */
    public function setPending(bool $pending): void
    {
        $this->pending = $pending;
    }

    /** @internal */
    public function createdFile(): bool
    {
        return $this->created;
    }
}

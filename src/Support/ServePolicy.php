<?php

namespace CodePulse2020\MediaUpload\Support;

use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Decides how a stored file is presented to browsers. Shared by the proxy
 * route, presigned URLs and the object metadata written to S3, so every path
 * answers with the same Content-Type, Content-Disposition and file name.
 *
 * @internal
 */
final class ServePolicy
{
    /** Formats the image handler can produce, even when absent from mime_map. */
    public const OUTPUT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
    ];

    /**
     * @param  array<string, list<string>>  $mimeMap
     * @param  list<string>  $inlineMimes
     */
    public function __construct(
        private readonly array $mimeMap,
        private readonly array $inlineMimes,
        private readonly bool $cache = true,
        private readonly string $publicDirectory = 'public',
    ) {
    }

    /** Prefix that holds every public profile's files. */
    public function publicDirectory(): string
    {
        return trim($this->publicDirectory, '/');
    }

    /** MIME type from the extension we chose when storing; unknown => opaque bytes. */
    public function mimeForPath(string $path): string
    {
        $extension = FilenameSanitizer::extension($path);

        foreach ($this->mimeMap as $mime => $extensions) {
            if (in_array($extension, array_map('strtolower', $extensions), true)) {
                return $mime;
            }
        }

        return self::OUTPUT_TYPES[$extension] ?? 'application/octet-stream';
    }

    public function isInline(string $mime, ?string $profileDisposition = null): bool
    {
        return $profileDisposition !== 'attachment' && in_array($mime, $this->inlineMimes, true);
    }

    /**
     * Download name: the client's name with the extension of the bytes we
     * actually store ("photo.jpg" converted to WebP => "photo.webp").
     */
    public function downloadName(string $originalName, string $storedExtension): string
    {
        $stem = FilenameSanitizer::stem(FilenameSanitizer::sanitize($originalName));

        return FilenameSanitizer::sanitize($stem.'.'.$storedExtension);
    }

    /** RFC 6266 header value with an ASCII fallback for non-ASCII names. */
    public function disposition(string $mime, string $filename, ?string $profileDisposition = null, bool $forceDownload = false): string
    {
        $type = ! $forceDownload && $this->isInline($mime, $profileDisposition)
            ? HeaderUtils::DISPOSITION_INLINE
            : HeaderUtils::DISPOSITION_ATTACHMENT;

        $filename = FilenameSanitizer::sanitize($filename);
        $fallback = preg_replace('/[^\x20-\x7E]|%/', '_', $filename) ?: 'file';

        return HeaderUtils::makeDisposition($type, $filename, $fallback);
    }

    /** Never lets a cached copy outlive the URL that fetched it. */
    public function cacheControl(int $secondsLeft): string
    {
        return $this->cache && $secondsLeft > 0
            ? 'private, max-age='.$secondsLeft
            : 'private, no-store';
    }
}

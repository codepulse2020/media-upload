<?php

namespace CodePulse2020\MediaUpload;

use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Pipeline\HookRunner;
use CodePulse2020\MediaUpload\Support\MimeMap;

/**
 * An immutable, fully resolved set of upload rules.
 *
 * @internal use MediaUpload::rules() for a stable, read-only view.
 */
final class Profile
{
    /**
     * @param  list<string>  $mimes
     * @param  array<string, mixed>  $image
     * @param  array<string, list<mixed>>  $hooks
     */
    public function __construct(
        public readonly string $name,
        public readonly array $mimes,
        public readonly int $maxSizeKb,
        public readonly string $disk,
        public readonly string $directory,
        public readonly array $image,
        public readonly string $disposition,
        public readonly array $hooks,
        public readonly string $visibility = 'private',
        public readonly array $office = [],
        public readonly string $pendingDisk = 'local',
        public readonly string $pendingDirectory = 'pending',
        public readonly ?string $dateDirectory = 'Y/m',
    ) {
    }

    /** Sub directory under $directory for a new upload, e.g. "2026/11"; "" when disabled. */
    public function dateSubdirectory(?int $timestamp = null): string
    {
        return $this->dateDirectory === null ? '' : date($this->dateDirectory, $timestamp ?? time());
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    /** @param array<string, mixed> $config the whole media-upload config */
    public static function fromConfig(string $name, array $config): self
    {
        $profile = $config['profiles'][$name] ?? null;

        if (! is_array($profile)) {
            throw new InvalidConfigException("Upload profile [{$name}] is not defined.");
        }

        $mimes = array_values($profile['mimes'] ?? []);

        if ($mimes === []) {
            throw new InvalidConfigException("Upload profile [{$name}] must list at least one MIME type.");
        }

        $map = new MimeMap($config['mime_map'] ?? []);

        foreach ($mimes as $mime) {
            if (! $map->has($mime)) {
                throw new InvalidConfigException("Profile [{$name}] allows [{$mime}] which is missing from mime_map.");
            }
        }

        $maxSize = (int) ($profile['max_size'] ?? 0);

        if ($maxSize <= 0) {
            throw new InvalidConfigException("Upload profile [{$name}] must define a positive max_size (KB).");
        }

        $disposition = $profile['disposition'] ?? 'inline';

        if (! in_array($disposition, ['inline', 'attachment'], true)) {
            throw new InvalidConfigException("Profile [{$name}] disposition must be inline or attachment.");
        }

        $visibility = $profile['visibility'] ?? 'private';

        if (! in_array($visibility, ['public', 'private'], true)) {
            throw new InvalidConfigException("Profile [{$name}] visibility must be public or private.");
        }

        $directory = trim($profile['directory'] ?? ($config['directory'] ?? 'uploads').'/'.$name, '/');
        $publicDirectory = trim((string) ($config['public']['directory'] ?? 'public'), '/');

        if ($visibility === 'public') {
            self::assertPublicSafe($name, $mimes, $config);
            $disk = $profile['disk'] ?? $config['public']['disk'] ?? $config['disk'] ?? 'local';
            $directory = trim($publicDirectory.'/'.$directory, '/');
        } else {
            $disk = $profile['disk'] ?? $config['disk'] ?? 'local';

            // Keep private files out of the prefix a bucket policy / CDN exposes.
            if ($publicDirectory !== '' && ($directory === $publicDirectory || str_starts_with($directory, $publicDirectory.'/'))) {
                throw new InvalidConfigException("Private profile [{$name}] may not use the public directory [{$publicDirectory}].");
            }
        }

        return new self(
            name: $name,
            mimes: $mimes,
            maxSizeKb: $maxSize,
            disk: $disk,
            directory: $directory,
            image: array_replace(
                [
                    'max_pixels' => 40_000_000,
                    'max_width' => 8192,
                    'max_height' => 8192,
                    'resize' => null,
                    'format' => null,
                    'quality' => 90,
                ],
                $config['image'] ?? [],
                $profile['image'] ?? [],
            ),
            disposition: $visibility === 'public' ? 'inline' : $disposition,
            hooks: self::mergeHooks($config['hooks'] ?? [], $profile['hooks'] ?? [], $name),
            visibility: $visibility,
            office: array_replace(
                ['allow_macros' => false, 'max_entries' => 10_000, 'max_uncompressed_size' => 100 * 1024 * 1024],
                $config['office'] ?? [],
                $profile['office'] ?? [],
            ),
            // Pending uploads always wait on the private disk, even for public profiles.
            pendingDisk: $config['disk'] ?? 'local',
            pendingDirectory: trim(($config['pending']['directory'] ?? 'pending').'/'.$name, '/'),
            dateDirectory: self::dateDirectory(
                $name,
                array_key_exists('date_directory', $profile) ? $profile['date_directory'] : ($config['date_directory'] ?? 'Y/m'),
            ),
        );
    }

    /**
     * Only numeric date characters (so every segment stays a plain name),
     * separated by "/", "-" or "_". null or "" turns the date layer off.
     */
    private static function dateDirectory(string $name, mixed $format): ?string
    {
        if ($format === null || $format === '') {
            return null;
        }

        if (! is_string($format) || ! preg_match('#^[YyomndjzWNwHG]+(?:[-_]*[YyomndjzWNwHG]+)*(?:/[YyomndjzWNwHG]+(?:[-_]*[YyomndjzWNwHG]+)*)*$#', $format)) {
            throw new InvalidConfigException("Profile [{$name}] date_directory must be a date() format using only Y y o m n d j z W N w H G, separated by \"/\", \"-\" or \"_\".");
        }

        return $format;
    }

    /**
     * Public files are reachable without a signature, so only types that are
     * decoded and re-encoded from pixels may be public. Anything stored as-is
     * (PDF, Office, SVG, passthrough) stays private.
     *
     * @param  list<string>  $mimes
     * @param  array<string, mixed>  $config
     */
    private static function assertPublicSafe(string $name, array $mimes, array $config): void
    {
        foreach ($mimes as $mime) {
            $handler = $config['handlers'][$mime] ?? $config['handlers']['*'] ?? null;

            if (! is_string($handler) || ! is_a($handler, Handlers\ImageHandler::class, true)) {
                throw new InvalidConfigException(
                    "Profile [{$name}] is public but [{$mime}] is not re-encoded by ImageHandler. Only raster images may be public."
                );
            }
        }
    }

    public function maxBytes(): int
    {
        return $this->maxSizeKb * 1024;
    }

    /** @return list<mixed> */
    public function hooksFor(string $point): array
    {
        return $this->hooks[$point] ?? [];
    }

    /**
     * @param  array<string, list<mixed>>  $global
     * @param  array<string, list<mixed>>  $local
     * @return array<string, list<mixed>>
     */
    private static function mergeHooks(array $global, array $local, string $name): array
    {
        foreach (array_keys($global + $local) as $point) {
            if (! in_array($point, HookRunner::POINTS, true)) {
                throw new InvalidConfigException(
                    "Unknown hook point [{$point}] in profile [{$name}]. Valid: ".implode(', ', HookRunner::POINTS).'.'
                );
            }
        }

        $hooks = [];

        foreach (HookRunner::POINTS as $point) {
            $hooks[$point] = array_merge(array_values($global[$point] ?? []), array_values($local[$point] ?? []));
        }

        return $hooks;
    }
}

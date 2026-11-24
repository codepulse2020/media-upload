<?php

namespace CodePulse\MediaUpload;

use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Pipeline\HookRunner;
use CodePulse\MediaUpload\Support\MimeMap;

/**
 * An immutable, fully resolved set of upload rules.
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
    ) {
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

        return new self(
            name: $name,
            mimes: $mimes,
            maxSizeKb: $maxSize,
            disk: $profile['disk'] ?? $config['disk'] ?? 'local',
            directory: trim($profile['directory'] ?? ($config['directory'] ?? 'uploads').'/'.$name, '/'),
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
            disposition: $disposition,
            hooks: self::mergeHooks($config['hooks'] ?? [], $profile['hooks'] ?? [], $name),
        );
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

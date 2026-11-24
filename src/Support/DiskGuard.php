<?php

namespace CodePulse\MediaUpload\Support;

use CodePulse\MediaUpload\Exceptions\InvalidConfigException;

/** @internal */
final class DiskGuard
{
    /** Canned ACLs that make an S3 object readable without a signature. */
    private const PUBLIC_ACLS = ['public-read', 'public-read-write', 'authenticated-read'];

    /** @param array<string, mixed> $diskConfigs config('filesystems.disks') */
    public static function assertPrivate(string $disk, array $diskConfigs): void
    {
        $config = self::effectiveConfig($disk, $diskConfigs);

        if (($config['visibility'] ?? null) === 'public') {
            throw new InvalidConfigException("Disk [{$disk}] is public. Uploads must go to a private disk.");
        }

        foreach ([$config['options']['ACL'] ?? null, $config['options']['params']['ACL'] ?? null] as $acl) {
            if (is_string($acl) && in_array(strtolower($acl), self::PUBLIC_ACLS, true)) {
                throw new InvalidConfigException("Disk [{$disk}] uses the public ACL [{$acl}]. Uploads must go to a private disk.");
            }
        }

        if (($config['driver'] ?? null) === 'local' && isset($config['root']) && function_exists('public_path')) {
            $root = self::normalise((string) $config['root'].'/'.($config['prefix'] ?? ''));
            $public = self::normalise(public_path());

            if (str_starts_with($root, $public)) {
                throw new InvalidConfigException("Disk [{$disk}] is inside the public directory.");
            }
        }
    }

    /**
     * Resolves "scoped" disks to the configuration that is actually used, the
     * same way Laravel's FilesystemManager does (parent config + prefix +
     * visibility override).
     *
     * @param  array<string, mixed>  $diskConfigs
     * @return array<string, mixed>
     */
    public static function effectiveConfig(string|array $disk, array $diskConfigs, int $depth = 0): array
    {
        if ($depth > 10) {
            throw new InvalidConfigException('Scoped disks are nested too deeply.');
        }

        $config = is_array($disk) ? $disk : ($diskConfigs[$disk] ?? null);

        if (! is_array($config)) {
            throw new InvalidConfigException('Filesystem disk ['.(is_string($disk) ? $disk : 'inline').'] is not configured.');
        }

        if (($config['driver'] ?? null) !== 'scoped') {
            return $config;
        }

        if (empty($config['disk'])) {
            throw new InvalidConfigException('Scoped disk is missing its "disk" option.');
        }

        $parent = self::effectiveConfig($config['disk'], $diskConfigs, $depth + 1);
        $parent['prefix'] = trim(($parent['prefix'] ?? '').'/'.($config['prefix'] ?? ''), '/');

        if (isset($config['visibility'])) {
            $parent['visibility'] = $config['visibility'];
        }

        return $parent;
    }

    private static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return '/'.implode('/', $parts).'/';
    }
}

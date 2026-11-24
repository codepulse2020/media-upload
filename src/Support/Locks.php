<?php

namespace CodePulse\MediaUpload\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Serialises work on one storage key across processes when the cache store
 * supports locks; runs unlocked otherwise.
 *
 * @internal
 */
final class Locks
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(?CacheFactory $cache, string $key, callable $callback, int $wait = 10): mixed
    {
        $store = $cache?->store();

        if ($store === null || ! $store->getStore() instanceof LockProvider) {
            return $callback();
        }

        return $store->getStore()->lock(self::name($key), 30)->block($wait, $callback);
    }

    /** Lock for a final storage location: "{disk}:{path}". */
    public static function forTarget(string $disk, string $path): string
    {
        return $disk.':'.$path;
    }

    public static function name(string $key): string
    {
        return 'media-upload:'.sha1($key);
    }
}

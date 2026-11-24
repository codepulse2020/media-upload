<?php

namespace CodePulse\MediaUpload\Console;

use Illuminate\Console\Command;

/**
 * Deletes unclaimed pending uploads and leftovers of queued uploads.
 * On S3 in production, an S3 lifecycle rule on the same prefixes is cheaper
 * than listing objects; this command then only needs to run locally.
 *
 * @internal
 */
final class PruneCommand extends Command
{
    protected $signature = 'media-upload:prune';

    protected $description = 'Delete unclaimed pending uploads and stale quarantine files';

    /** @var list<string> kept for 0.x schedules */
    protected $aliases = ['media-upload:prune-quarantine'];

    public function handle(): int
    {
        $pending = $this->prune(
            (string) (config('media-upload.disk') ?? 'local'),
            (string) (config('media-upload.pending.directory') ?? 'pending'),
            (int) (config('media-upload.pending.ttl') ?? 1440),
        );

        $quarantine = $this->prune(
            (string) (config('media-upload.queue.quarantine_disk') ?? 'local'),
            (string) (config('media-upload.queue.quarantine_dir') ?? 'quarantine'),
            (int) (config('media-upload.queue.quarantine_ttl') ?? 1440),
        );

        $this->info("Deleted {$pending} pending and {$quarantine} quarantined file(s).");

        return self::SUCCESS;
    }

    private function prune(string $disk, string $directory, int $ttlMinutes): int
    {
        $storage = app('filesystem')->disk($disk);
        $cutoff = now()->subMinutes($ttlMinutes)->getTimestamp();
        $deleted = 0;

        // Streamed listing: large pending areas are never loaded into memory.
        foreach ($storage->getDriver()->listContents(trim($directory, '/'), true) as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $modified = $item->lastModified() ?? $storage->lastModified($item->path());

            if ($modified < $cutoff) {
                $storage->delete($item->path());
                $deleted++;
            }
        }

        return $deleted;
    }
}

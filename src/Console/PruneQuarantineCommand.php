<?php

namespace CodePulse\MediaUpload\Console;

use Illuminate\Console\Command;

final class PruneQuarantineCommand extends Command
{
    protected $signature = 'media-upload:prune-quarantine';

    protected $description = 'Delete quarantined uploads older than media-upload.queue.quarantine_ttl minutes';

    public function handle(): int
    {
        $config = config('media-upload.queue');
        $disk = app('filesystem')->disk($config['quarantine_disk'] ?? 'local');
        $cutoff = now()->subMinutes((int) ($config['quarantine_ttl'] ?? 1440))->getTimestamp();
        $deleted = 0;

        foreach ($disk->files($config['quarantine_dir'] ?? 'quarantine') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} quarantined file(s).");

        return self::SUCCESS;
    }
}

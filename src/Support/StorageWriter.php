<?php

namespace CodePulse\MediaUpload\Support;

use Aws\CommandInterface;
use Aws\Middleware;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;

/**
 * Writes a stream privately on any disk.
 *
 * Local disks get private file permissions. On S3, Flysystem always sends a
 * canned ACL, which buckets with ACLs disabled (BucketOwnerEnforced, the S3
 * default since 2023) may refuse; access is left to the bucket policy instead.
 * Content-Type / Content-Disposition are written as object metadata so the
 * object is safe even when fetched outside this package.
 */
final class StorageWriter
{
    /**
     * @param  resource  $stream
     */
    public static function write(Filesystem $disk, string $path, $stream, ?string $mime = null, ?string $disposition = null): void
    {
        if (! $disk instanceof AwsS3V3Adapter) {
            $written = $disk->writeStream($path, $stream, ['visibility' => 'private']);

            if ($written === false) {
                throw new \RuntimeException("Unable to write [{$path}].");
            }

            return;
        }

        $options = array_filter([
            'ContentType' => $mime ?? 'application/octet-stream',
            'ContentDisposition' => $disposition,
        ]);

        $config = $disk->getConfig();
        $explicitAcl = ($config['visibility'] ?? null) !== null
            || isset($config['options']['ACL'])
            || isset($config['options']['params']['ACL']);

        if ($explicitAcl) {
            self::assertWritten($disk->writeStream($path, $stream, $options), $path);

            return;
        }

        $stripAcl = Middleware::mapCommand(static function (CommandInterface $command): CommandInterface {
            if (in_array($command->getName(), ['PutObject', 'CreateMultipartUpload'], true)) {
                unset($command['ACL']);
            }

            return $command;
        });

        $handlers = $disk->getClient()->getHandlerList();
        $handlers->appendInit($stripAcl);

        try {
            self::assertWritten($disk->writeStream($path, $stream, $options), $path);
        } finally {
            $handlers->remove($stripAcl);
        }
    }

    private static function assertWritten(bool $written, string $path): void
    {
        if (! $written) {
            throw new \RuntimeException("Unable to write [{$path}].");
        }
    }
}

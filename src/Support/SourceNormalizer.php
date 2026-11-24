<?php

namespace CodePulse\MediaUpload\Support;

use CodePulse\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use Illuminate\Http\UploadedFile;
use SplFileInfo;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Makes every source a local, readable file. Remote files (e.g. Livewire
 * temporary uploads on an S3 disk) and streams are copied to a temporary
 * file, enforcing the size limit while copying so a huge remote file is
 * never fully downloaded.
 *
 * @internal
 */
final class SourceNormalizer
{
    private const CHUNK = 1024 * 1024;

    /** @var list<string> */
    private array $temporary = [];

    public function normalize(SplFileInfo $file, int $maxBytes): SplFileInfo
    {
        $path = $file->getRealPath() ?: $file->getPathname();

        if (is_file($path) && is_readable($path)) {
            return $file;
        }

        if ($file instanceof UploadedFile && method_exists($file, 'readStream')) {
            $stream = $file->readStream();

            if (! is_resource($stream)) {
                throw Rejected::because(Rejected::UPLOAD_FAILED, 'The uploaded file is not readable.');
            }

            try {
                return $this->fromStream($stream, $maxBytes);
            } finally {
                is_resource($stream) && fclose($stream);
            }
        }

        throw Rejected::because(Rejected::UPLOAD_FAILED, 'The uploaded file is not readable.');
    }

    /** @param resource $stream */
    public function fromStream($stream, int $maxBytes): SplFileInfo
    {
        $path = tempnam(sys_get_temp_dir(), 'media-upload-src-');
        $this->temporary[] = $path;
        $out = fopen($path, 'wb');
        $copied = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK);

                if ($chunk === false) {
                    throw Rejected::because(Rejected::UPLOAD_FAILED, 'The uploaded file could not be read.');
                }

                $copied += strlen($chunk);

                if ($copied > $maxBytes) {
                    throw Rejected::because(Rejected::TOO_LARGE, 'The file may not be larger than '.intdiv($maxBytes, 1024).' KB.');
                }

                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }

        return new File($path);
    }

    /** Deletes every temporary copy made by this instance. */
    public function cleanup(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        $this->temporary = [];
    }
}

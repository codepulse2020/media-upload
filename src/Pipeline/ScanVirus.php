<?php

namespace CodePulse\MediaUpload\Pipeline;

use CodePulse\MediaUpload\Contracts\VirusScanner;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse\MediaUpload\Exceptions\ScanLimitExceededException;
use CodePulse\MediaUpload\Exceptions\ScannerUnavailableException;
use CodePulse\MediaUpload\UploadContext;

final class ScanVirus
{
    public function __construct(
        private readonly VirusScanner $scanner,
        private readonly bool $failClosed = true,
    ) {
    }

    public function __invoke(UploadContext $ctx): void
    {
        $stream = $ctx->outputStream();

        try {
            $signature = $this->scanner->scan($stream);
        } catch (ScanLimitExceededException $e) {
            // Never store what could not be scanned, regardless of fail_closed.
            throw Rejected::because(Rejected::TOO_LARGE_TO_SCAN, 'The file is too large to be scanned.', $e);
        } catch (ScannerUnavailableException $e) {
            if ($this->failClosed) {
                throw Rejected::because(Rejected::SCAN_UNAVAILABLE, 'The file could not be scanned. Please try again later.', $e);
            }

            report($e);

            return;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($signature !== null) {
            throw Rejected::because(Rejected::VIRUS_DETECTED, 'The file was flagged as malicious.');
        }
    }
}

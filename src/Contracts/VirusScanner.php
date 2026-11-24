<?php

namespace CodePulse\MediaUpload\Contracts;

use CodePulse\MediaUpload\Exceptions\ScannerUnavailableException;

/** @api */
interface VirusScanner
{
    /**
     * @param  resource  $stream
     * @return string|null signature name when infected, null when clean
     *
     * @throws ScannerUnavailableException
     */
    public function scan($stream): ?string;
}

<?php

namespace CodePulse2020\MediaUpload\Contracts;

use CodePulse2020\MediaUpload\Exceptions\ScannerUnavailableException;

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

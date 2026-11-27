<?php

namespace CodePulse2020\MediaUpload\Scanning;

use CodePulse2020\MediaUpload\Contracts\VirusScanner;

/** @api */
final class NullScanner implements VirusScanner
{
    public function scan($stream): ?string
    {
        return null;
    }
}

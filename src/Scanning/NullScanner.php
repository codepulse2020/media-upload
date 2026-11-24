<?php

namespace CodePulse\MediaUpload\Scanning;

use CodePulse\MediaUpload\Contracts\VirusScanner;

final class NullScanner implements VirusScanner
{
    public function scan($stream): ?string
    {
        return null;
    }
}

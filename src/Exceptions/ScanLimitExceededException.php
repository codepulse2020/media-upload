<?php

namespace CodePulse\MediaUpload\Exceptions;

use RuntimeException;

/**
 * The file is larger than the scanner can inspect. It is always rejected,
 * whatever virus_scan.fail_closed says: an unscannable file is never stored.
 *
 * @api
 */
class ScanLimitExceededException extends RuntimeException
{
}

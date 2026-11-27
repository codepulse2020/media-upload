<?php

namespace CodePulse2020\MediaUpload\Contracts;

use Closure;
use CodePulse2020\MediaUpload\UploadContext;

/**
 * A business hook. Works like Laravel middleware: call $next($ctx) to continue.
 * Not calling $next rejects the upload; throw RejectedUploadException to reject
 * with your own message.
 *
 * @api
 */
interface Stage
{
    public function handle(UploadContext $ctx, Closure $next): UploadContext;
}

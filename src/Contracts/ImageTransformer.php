<?php

namespace CodePulse2020\MediaUpload\Contracts;

use CodePulse2020\MediaUpload\UploadContext;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Runs on the decoded image at the "transform" hook point, before the image is
 * re-encoded. Use it for watermarks, crops, resizing, blurring…
 *
 * @api
 */
interface ImageTransformer
{
    public function transform(ImageInterface $image, UploadContext $ctx): ImageInterface;
}

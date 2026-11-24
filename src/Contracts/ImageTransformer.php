<?php

namespace CodePulse\MediaUpload\Contracts;

use CodePulse\MediaUpload\UploadContext;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Runs on the decoded image at the "transform" hook point, before the image is
 * re-encoded. Use it for watermarks, crops, resizing, blurring…
 */
interface ImageTransformer
{
    public function transform(ImageInterface $image, UploadContext $ctx): ImageInterface;
}

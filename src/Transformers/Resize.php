<?php

namespace CodePulse2020\MediaUpload\Transformers;

use CodePulse2020\MediaUpload\Contracts\ImageTransformer;
use CodePulse2020\MediaUpload\UploadContext;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Shrinks the image to fit inside width x height, keeping aspect ratio. Never upscales.
 *
 * @api
 */
final class Resize implements ImageTransformer
{
    public function __construct(
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
    }

    public function transform(ImageInterface $image, UploadContext $ctx): ImageInterface
    {
        return $image->scaleDown($this->width, $this->height);
    }
}

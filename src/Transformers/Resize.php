<?php

namespace CodePulse\MediaUpload\Transformers;

use CodePulse\MediaUpload\Contracts\ImageTransformer;
use CodePulse\MediaUpload\UploadContext;
use Intervention\Image\Interfaces\ImageInterface;

/** Shrinks the image to fit inside width x height, keeping aspect ratio. Never upscales. */
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

<?php

namespace CodePulse\MediaUpload\Transformers;

use CodePulse\MediaUpload\Contracts\ImageTransformer;
use CodePulse\MediaUpload\UploadContext;
use Intervention\Image\Interfaces\ImageInterface;

/** Crops and resizes to exactly width x height (CSS object-fit: cover). */
final class Fit implements ImageTransformer
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $position = 'center',
    ) {
    }

    public function transform(ImageInterface $image, UploadContext $ctx): ImageInterface
    {
        return $image->cover($this->width, $this->height, $this->position);
    }
}

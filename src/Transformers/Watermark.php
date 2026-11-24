<?php

namespace CodePulse\MediaUpload\Transformers;

use CodePulse\MediaUpload\Contracts\ImageTransformer;
use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\UploadContext;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;

/**
 * Image or text watermark.
 *
 *   new Watermark(path: storage_path('app/logo.png'), position: 'bottom-right', opacity: 60, scale: 0.2)
 *   new Watermark(text: 'ACME', font: resource_path('fonts/Inter.ttf'), position: 'center', opacity: 30)
 *   new Watermark(path: $logo, tile: true, scale: 0.15, opacity: 20)
 *
 * Runs before re-encoding, so the result is still stripped and re-encoded.
 */
final class Watermark implements ImageTransformer
{
    public const POSITIONS = [
        'top-left', 'top', 'top-right',
        'left', 'center', 'right',
        'bottom-left', 'bottom', 'bottom-right',
    ];

    /**
     * @param  string|null  $path  watermark image (PNG with transparency recommended)
     * @param  string|null  $text  text watermark (use instead of $path)
     * @param  float  $scale  watermark width relative to the image width (image) / font size relative to image width (text)
     * @param  int  $margin  distance from the edges in pixels
     * @param  int  $opacity  0 – 100
     * @param  bool  $tile  repeat the image watermark across the whole image
     * @param  string|null  $font  TTF/OTF file for text watermarks (GD falls back to a tiny bitmap font without it)
     * @param  string  $color  hex colour for text watermarks
     */
    public function __construct(
        public readonly ?string $path = null,
        public readonly ?string $text = null,
        public readonly string $position = 'bottom-right',
        public readonly float $scale = 0.2,
        public readonly int $margin = 16,
        public readonly int $opacity = 60,
        public readonly bool $tile = false,
        public readonly ?string $font = null,
        public readonly string $color = 'ffffff',
    ) {
        if (($path === null) === ($text === null)) {
            throw new InvalidConfigException('Watermark needs exactly one of [path] or [text].');
        }

        if (! in_array($position, self::POSITIONS, true)) {
            throw new InvalidConfigException("Invalid watermark position [{$position}].");
        }

        if ($scale <= 0 || $scale > 1) {
            throw new InvalidConfigException('Watermark scale must be between 0 and 1.');
        }

        if ($opacity < 0 || $opacity > 100) {
            throw new InvalidConfigException('Watermark opacity must be between 0 and 100.');
        }

        if ($tile && $text !== null) {
            throw new InvalidConfigException('Tiling is only supported for image watermarks.');
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', ltrim($color, '#'))) {
            throw new InvalidConfigException('Watermark color must be a 6 digit hex value.');
        }
    }

    public function transform(ImageInterface $image, UploadContext $ctx): ImageInterface
    {
        return $this->path !== null
            ? $this->placeImage($image)
            : $this->placeText($image);
    }

    private function placeImage(ImageInterface $image): ImageInterface
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new InvalidConfigException("Watermark image [{$this->path}] is not readable.");
        }

        $mark = (new ImageManager($image->driver()))->read($this->path);
        $mark->scale(width: max(1, (int) round($image->width() * $this->scale)));

        if (! $this->tile) {
            return $image->place($mark, $this->position, $this->margin, $this->margin, $this->opacity);
        }

        $stepX = $mark->width() + max(1, $this->margin) * 2;
        $stepY = $mark->height() + max(1, $this->margin) * 2;

        for ($y = $this->margin; $y < $image->height(); $y += $stepY) {
            for ($x = $this->margin; $x < $image->width(); $x += $stepX) {
                $image->place($mark, 'top-left', $x, $y, $this->opacity);
            }
        }

        return $image;
    }

    private function placeText(ImageInterface $image): ImageInterface
    {
        if ($this->font !== null && ! is_readable($this->font)) {
            throw new InvalidConfigException("Watermark font [{$this->font}] is not readable.");
        }

        [$vertical, $horizontal] = $this->axes();

        $x = match ($horizontal) {
            'left' => $this->margin,
            'right' => $image->width() - $this->margin,
            default => intdiv($image->width(), 2),
        };

        $y = match ($vertical) {
            'top' => $this->margin,
            'bottom' => $image->height() - $this->margin,
            default => intdiv($image->height(), 2),
        };

        $alpha = round($this->opacity / 100, 2);
        $rgb = sscanf(ltrim($this->color, '#'), '%2x%2x%2x');
        $size = max(8, (int) round($image->width() * $this->scale * 0.25));

        return $image->text($this->text, $x, $y, function (FontFactory $font) use ($horizontal, $vertical, $alpha, $rgb, $size) {
            if ($this->font !== null) {
                $font->file($this->font);
            }

            $font->size($size);
            $font->color("rgba({$rgb[0]}, {$rgb[1]}, {$rgb[2]}, {$alpha})");
            $font->align($horizontal);
            $font->valign($vertical);
        });
    }

    /** @return array{0: 'top'|'middle'|'bottom', 1: 'left'|'center'|'right'} */
    private function axes(): array
    {
        $parts = explode('-', $this->position);

        $vertical = in_array($parts[0], ['top', 'bottom'], true) ? $parts[0] : 'middle';
        $horizontal = match (true) {
            in_array('left', $parts, true) => 'left',
            in_array('right', $parts, true) => 'right',
            default => 'center',
        };

        return [$vertical, $horizontal];
    }
}

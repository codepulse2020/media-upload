<?php

namespace CodePulse\MediaUpload\Handlers;

use CodePulse\MediaUpload\Contracts\FileHandler;
use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse\MediaUpload\Support\FrameCounter;
use CodePulse\MediaUpload\Support\MimeMap;
use CodePulse\MediaUpload\UploadContext;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Decodes raster images and re-encodes them from pixels. Anything that is not
 * pixel data (polyglot payloads, EXIF/GPS, comments, trailing bytes) is lost.
 *
 * @api
 */
final class ImageHandler implements FileHandler
{
    private const OUTPUT_FORMATS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
    ];

    private const IMAGICK_CODERS = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public function __construct(private readonly MimeMap $mimeMap)
    {
    }

    public function decode(UploadContext $ctx): void
    {
        $options = $ctx->profile->image;

        // getimagesize() reads only the header, so huge dimensions are refused
        // before any pixel buffer is allocated (decompression bombs).
        $info = @getimagesize($ctx->path());

        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw Rejected::because(Rejected::CORRUPT_FILE, 'The image could not be read.');
        }

        [$width, $height] = $info;

        if (image_type_to_mime_type($info[2]) !== $ctx->mime()) {
            throw Rejected::because(Rejected::CORRUPT_FILE, 'The image header does not match its type.');
        }

        if ($width * $height > (int) $options['max_pixels']
            || $width > (int) $options['max_width']
            || $height > (int) $options['max_height']) {
            throw Rejected::because(
                Rejected::IMAGE_TOO_LARGE,
                "The image may not exceed {$options['max_width']}x{$options['max_height']} pixels.",
            );
        }

        $animated = (bool) ($options['animation'] ?? false);
        $frames = FrameCounter::count($ctx->path(), (string) $ctx->mime());
        $maxFrames = (int) ($options['max_frames'] ?? 100);

        // Each decoded frame is a full canvas: limit the count and the pixels of all frames together.
        if ($animated && $frames > $maxFrames) {
            throw Rejected::because(Rejected::IMAGE_TOO_LARGE, "Animations may not have more than {$maxFrames} frames.");
        }

        if ($animated && $width * $height * $frames > (int) ($options['max_animation_pixels'] ?? 100_000_000)) {
            throw Rejected::because(Rejected::IMAGE_TOO_LARGE, 'The animation is too large.');
        }

        try {
            $image = $this->manager($options)->read($this->source($ctx, $options, $animated));
        } catch (\Throwable $e) {
            throw Rejected::because(Rejected::CORRUPT_FILE, 'The image could not be decoded.', $e);
        }

        $ctx->setImage($image);
    }

    public function encode(UploadContext $ctx): void
    {
        $image = $ctx->image();
        $options = $ctx->profile->image;

        $extension = strtolower((string) ($options['format'] ?? $this->mimeMap->defaultExtension((string) $ctx->mime())));

        if (! isset(self::OUTPUT_FORMATS[$extension])) {
            throw new InvalidConfigException("Unsupported image output format [{$extension}].");
        }

        if ((bool) ($options['strip_metadata'] ?? true)) {
            $this->stripNative($image);
        }

        try {
            $encoded = $image->encodeByExtension(
                $extension,
                quality: (int) ($options['quality'] ?? 90),
                strip: (bool) ($options['strip_metadata'] ?? true),
            );
        } catch (\Throwable $e) {
            throw Rejected::because(Rejected::CORRUPT_FILE, 'The image could not be processed.', $e);
        }

        $ctx->setOutput(
            contents: $encoded->toString(),
            mime: self::OUTPUT_FORMATS[$extension],
            extension: $extension === 'jpeg' ? 'jpg' : $extension,
            width: $image->width(),
            height: $image->height(),
        );
    }

    /**
     * GD: the file path (GD only ever decodes the first frame unless Intervention's
     * own GIF decoder is asked for animation).
     * Imagick: read natively with an explicit coder, so ImageMagick cannot pick
     * a different (dangerous) coder from the content, and with "[0]" so only the
     * first frame is decoded when animation is off.
     *
     * @param  array<string, mixed>  $options
     */
    private function source(UploadContext $ctx, array $options, bool $animated): mixed
    {
        if (($options['driver'] ?? 'gd') !== 'imagick') {
            return $ctx->path();
        }

        $coder = self::IMAGICK_CODERS[(string) $ctx->mime()] ?? null;

        if ($coder === null) {
            throw Rejected::because(Rejected::MIME_NOT_ALLOWED, 'This image type is not supported.');
        }

        // Enforce the frame limit inside ImageMagick too (process-wide, so restore it).
        $limit = defined('Imagick::RESOURCETYPE_LISTLENGTH') ? \Imagick::RESOURCETYPE_LISTLENGTH : null;
        $previous = $limit !== null ? \Imagick::getResourceLimit($limit) : null;

        if ($limit !== null) {
            // ImageMagick refuses a sequence once it reaches the limit, hence "+ 1".
            \Imagick::setResourceLimit($limit, ($animated ? max(1, (int) ($options['max_frames'] ?? 100)) : 1) + 1);
        }

        try {
            $native = new \Imagick();
            $native->readImage($coder.':'.$ctx->path().($animated ? '' : '[0]'));
        } finally {
            if ($limit !== null) {
                \Imagick::setResourceLimit($limit, $previous);
            }
        }

        return $native;
    }

    /**
     * Intervention drops the "strip" option for encoders that do not accept it
     * (e.g. Imagick PNG), so remove profiles, comments and text chunks here.
     */
    private function stripNative(ImageInterface $image): void
    {
        $native = $image->core()->native();

        if (! $native instanceof \Imagick) {
            return; // GD never writes source metadata.
        }

        $count = $native->getNumberImages();

        for ($index = 0; $index < $count; $index++) {
            $native->setIteratorIndex($index);
            $native->stripImage();
        }

        $native->setIteratorIndex(0);
    }

    /** @param array<string, mixed> $options */
    private function manager(array $options): ImageManager
    {
        return ImageManager::withDriver(
            match ($options['driver'] ?? 'gd') {
                'imagick' => \Intervention\Image\Drivers\Imagick\Driver::class,
                'gd' => \Intervention\Image\Drivers\Gd\Driver::class,
                default => throw new InvalidConfigException("Unknown image driver [{$options['driver']}]."),
            },
            autoOrientation: (bool) ($options['auto_orient'] ?? true),
            // Only the first frame of animations by default: frames multiply memory use.
            decodeAnimation: (bool) ($options['animation'] ?? false),
            strip: (bool) ($options['strip_metadata'] ?? true),
        );
    }
}

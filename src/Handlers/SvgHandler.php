<?php

namespace CodePulse2020\MediaUpload\Handlers;

use CodePulse2020\MediaUpload\Contracts\FileHandler;
use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException as Rejected;
use CodePulse2020\MediaUpload\UploadContext;
use enshrined\svgSanitize\Sanitizer;

/**
 * SVG is XML that can carry scripts. Disabled unless svg.enabled is true, and
 * then always rewritten by enshrined/svg-sanitize.
 *
 * @api
 */
final class SvgHandler implements FileHandler
{
    private ?string $clean = null;

    public function __construct(private readonly bool $enabled = false)
    {
    }

    public function decode(UploadContext $ctx): void
    {
        if (! $this->enabled) {
            throw Rejected::because(Rejected::SVG_DISABLED, 'SVG uploads are not allowed.');
        }

        if (! class_exists(Sanitizer::class)) {
            throw new InvalidConfigException('svg.enabled requires the enshrined/svg-sanitize package.');
        }

        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->removeXMLTag(true);
        $sanitizer->minify(false);

        $clean = $sanitizer->sanitize((string) file_get_contents($ctx->path()));

        if ($clean === false || trim($clean) === '') {
            throw Rejected::because(Rejected::UNSAFE_CONTENT, 'The SVG could not be sanitised.');
        }

        $this->clean = $clean;
    }

    public function encode(UploadContext $ctx): void
    {
        $ctx->setOutput($this->clean, 'image/svg+xml', 'svg');
    }
}

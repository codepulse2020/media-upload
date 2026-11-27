<?php

namespace CodePulse2020\MediaUpload;

use CodePulse2020\MediaUpload\Support\MimeMap;

/**
 * Read-only view of a profile's rules, e.g. to configure a form component
 * (accepted types, size limit) before anything is uploaded.
 *
 * @api
 */
final class UploadRules
{
    /**
     * @param  list<string>  $mimes  real MIME types accepted
     * @param  list<string>  $extensions  client extensions accepted
     * @param  string  $disk  disk the profile writes to (profile.disk, then public.disk for public profiles, then disk)
     * @param  string  $directory  root directory of the profile, without the {year}/{month} part;
     *                             includes the public prefix for public profiles (e.g. "public/media")
     * @param  ?string  $dateDirectory  date() format of the sub directory added under $directory
     *                                  (e.g. "Y/m"), null when files go directly into $directory
     */
    public function __construct(
        public readonly string $profile,
        public readonly array $mimes,
        public readonly array $extensions,
        public readonly int $maxSizeKb,
        public readonly ?int $maxWidth,
        public readonly ?int $maxHeight,
        public readonly string $visibility,
        public readonly string $disposition,
        public readonly bool $imagesOnly,
        public readonly string $disk = 'local',
        public readonly string $directory = '',
        public readonly ?string $dateDirectory = 'Y/m',
    ) {
    }

    /** @internal */
    public static function fromProfile(Profile $profile, MimeMap $mimeMap): self
    {
        $imagesOnly = array_filter($profile->mimes, fn (string $mime) => ! str_starts_with($mime, 'image/')) === [];

        return new self(
            profile: $profile->name,
            mimes: $profile->mimes,
            extensions: $mimeMap->extensionsForAll($profile->mimes),
            maxSizeKb: $profile->maxSizeKb,
            maxWidth: $imagesOnly ? (int) $profile->image['max_width'] : null,
            maxHeight: $imagesOnly ? (int) $profile->image['max_height'] : null,
            visibility: $profile->visibility,
            disposition: $profile->disposition,
            imagesOnly: $imagesOnly,
            disk: $profile->disk,
            directory: $profile->directory,
            dateDirectory: $profile->dateDirectory,
        );
    }

    /** "image/jpeg,image/png" style string for an HTML accept attribute. */
    public function accept(): string
    {
        return implode(',', $this->mimes);
    }
}

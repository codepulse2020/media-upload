<?php

namespace CodePulse2020\MediaUpload\Rules;

use Closure;
use CodePulse2020\MediaUpload\Exceptions\InvalidUploadTokenException;
use CodePulse2020\MediaUpload\MediaUploader;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only tokens issued for an upload of the given profile (and owner).
 * Replaces "the path exists on the disk" checks in two-step flows.
 *
 *   'attachments.*' => ['required', 'string', new UploadToken('contact', owner: fn () => auth()->id())],
 *
 * Resolve afterwards with MediaUpload::fromToken() or MediaUpload::claim().
 *
 * @api
 */
final class UploadToken implements ValidationRule
{
    /** @param string|int|Closure(): (string|int|null)|null $owner */
    public function __construct(
        private readonly string $profile,
        private readonly string|int|Closure|null $owner = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute is not a valid upload.');

            return;
        }

        $owner = $this->owner instanceof Closure ? ($this->owner)() : $this->owner;

        try {
            app(MediaUploader::class)->fromToken($value, $this->profile, $owner);
        } catch (InvalidUploadTokenException $e) {
            $fail(match ($e->reason) {
                InvalidUploadTokenException::EXPIRED => 'The :attribute has expired. Please upload it again.',
                InvalidUploadTokenException::MISSING => 'The :attribute no longer exists. Please upload it again.',
                default => 'The :attribute is not a valid upload.',
            });
        }
    }
}

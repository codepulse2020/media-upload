<?php

namespace CodePulse\MediaUpload\Rules;

use Closure;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse\MediaUpload\MediaUploader;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Early feedback in a FormRequest: size, extension and real MIME checks.
 * Decoding / re-encoding / scanning still happen when you call store().
 *
 *   'photo' => ['required', new SafeFile('avatar')],
 */
final class SafeFile implements ValidationRule
{
    public function __construct(private readonly ?string $profile = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be a file.');

            return;
        }

        try {
            app(MediaUploader::class)->profile($this->profile)->validate($value);
        } catch (RejectedUploadException $e) {
            $fail($e->getMessage());
        }
    }
}

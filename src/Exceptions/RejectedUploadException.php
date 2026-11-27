<?php

namespace CodePulse2020\MediaUpload\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown whenever an upload is refused. The reason code is stable and safe to
 * expose to clients; the message never contains file contents.
 *
 * @api
 */
class RejectedUploadException extends RuntimeException
{
    public const UPLOAD_FAILED = 'upload_failed';
    public const EMPTY_FILE = 'empty_file';
    public const TOO_LARGE = 'too_large';
    public const EXTENSION_MISSING = 'extension_missing';
    public const BLOCKED_EXTENSION = 'blocked_extension';
    public const EXTENSION_NOT_ALLOWED = 'extension_not_allowed';
    public const MIME_NOT_ALLOWED = 'mime_not_allowed';
    public const EXTENSION_MISMATCH = 'extension_mismatch';
    public const CORRUPT_FILE = 'corrupt_file';
    public const IMAGE_TOO_LARGE = 'image_too_large';
    public const SVG_DISABLED = 'svg_disabled';
    public const UNSAFE_CONTENT = 'unsafe_content';
    public const VIRUS_DETECTED = 'virus_detected';
    public const SCAN_UNAVAILABLE = 'scan_unavailable';
    public const HOOK_HALTED = 'hook_halted';
    public const HOOK_TAMPERED = 'hook_tampered';
    public const REJECTED_BY_HOOK = 'rejected_by_hook';
    public const PROCESSING_FAILED = 'processing_failed';
    public const PATH_CONFLICT = 'path_conflict';
    public const TOO_LARGE_TO_SCAN = 'too_large_to_scan';
    public const INVALID_DIRECTORY = 'invalid_directory';

    public function __construct(
        public readonly string $reason,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : "Upload rejected: {$reason}.", 0, $previous);
    }

    public static function because(string $reason, string $message = '', ?\Throwable $previous = null): static
    {
        return new static($reason, $message, $previous);
    }

    /** Convenient for business hooks: `throw RejectedUploadException::byHook('Quota exceeded.')`. */
    public static function byHook(string $message): static
    {
        return new static(self::REJECTED_BY_HOOK, $message);
    }

    public function toValidationException(string $attribute = 'file'): ValidationException
    {
        return ValidationException::withMessages([$attribute => $this->getMessage()]);
    }

    public function render(Request $request): Response
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'reason' => $this->reason,
        ], 422);
    }
}

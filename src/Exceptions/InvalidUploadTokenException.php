<?php

namespace CodePulse\MediaUpload\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** @api */
class InvalidUploadTokenException extends RuntimeException
{
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const WRONG_PROFILE = 'wrong_profile';
    public const WRONG_OWNER = 'wrong_owner';
    public const MISSING = 'missing';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Invalid upload token: {$reason}.");
    }

    public function render(Request $request): Response
    {
        return new JsonResponse(['message' => $this->getMessage(), 'reason' => $this->reason], 422);
    }
}

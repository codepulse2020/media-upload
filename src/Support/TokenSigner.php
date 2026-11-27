<?php

namespace CodePulse2020\MediaUpload\Support;

use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Exceptions\InvalidUploadTokenException as Invalid;

/**
 * Compact signed tokens: base64url(json) "." base64url(hmac-sha256).
 * The key is derived from APP_KEY for this purpose only, so a token can
 * never be confused with other Laravel signatures.
 *
 * @internal
 */
final class TokenSigner
{
    private const VERSION = 1;

    public function __construct(private readonly string $appKey)
    {
        if ($appKey === '') {
            throw new InvalidConfigException('Upload tokens need APP_KEY to be set.');
        }
    }

    /** @param array<string, mixed> $claims */
    public function sign(array $claims, int $ttlSeconds): string
    {
        $claims['v'] = self::VERSION;
        $claims['exp'] = time() + $ttlSeconds;

        $payload = self::encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload.'.'.self::encode($this->mac($payload));
    }

    /** @return array<string, mixed> */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || strlen($token) > 8192) {
            throw new Invalid(Invalid::INVALID);
        }

        [$payload, $signature] = $parts;

        if (! hash_equals(self::encode($this->mac($payload)), $signature)) {
            throw new Invalid(Invalid::INVALID);
        }

        $claims = json_decode((string) self::decode($payload), true);

        if (! is_array($claims) || ($claims['v'] ?? null) !== self::VERSION) {
            throw new Invalid(Invalid::INVALID);
        }

        if (! is_int($claims['exp'] ?? null) || $claims['exp'] < time()) {
            throw new Invalid(Invalid::EXPIRED);
        }

        return $claims;
    }

    private function mac(string $payload): string
    {
        $key = str_starts_with($this->appKey, 'base64:')
            ? (string) base64_decode(substr($this->appKey, 7), true)
            : $this->appKey;

        return hash_hmac('sha256', $payload, hash_hmac('sha256', 'media-upload-token', $key, true), true);
    }

    private static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function decode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}

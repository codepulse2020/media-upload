<?php

namespace CodePulse\MediaUpload\Support;

final class FilenameSanitizer
{
    /**
     * Turns a client supplied file name into something safe to display and log.
     * It is NEVER used as the storage name. Pass $maxLength = null to keep the
     * full name (used for validation, so truncation cannot hide a segment).
     */
    public static function sanitize(string $name, ?int $maxLength = 200): string
    {
        // Invalid UTF-8 (e.g. Big5 / Latin-1 names) would make every /u regex
        // below fail; replace the broken bytes instead of losing the name.
        if (! mb_check_encoding($name, 'UTF-8')) {
            $previous = mb_substitute_character();
            mb_substitute_character(0x5F); // "_"; "?" would be stripped below and eat the stem

            try {
                $name = mb_scrub($name, 'UTF-8');
            } finally {
                mb_substitute_character($previous);
            }
        }

        // Strip any path component, including Windows separators.
        $name = str_replace('\\', '/', $name);
        $name = basename($name);

        // Remove control characters (incl. NUL) and characters that are
        // meaningful in HTML, shells or paths.
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = preg_replace('/[<>:"\/\\\\|?*`$;&{}\[\]]/u', '', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name, " .\t");

        if ($maxLength !== null && mb_strlen($name) > $maxLength) {
            $extension = self::extension($name);
            $keep = $maxLength - ($extension === '' ? 0 : mb_strlen($extension) + 1);
            $name = mb_substr($name, 0, max(1, $keep)).($extension === '' ? '' : '.'.$extension);
        }

        return $name === '' ? 'file' : $name;
    }

    public static function extension(string $name): string
    {
        $position = strrpos($name, '.');

        return $position === false ? '' : strtolower(substr($name, $position + 1));
    }

    /** Name without its last extension. */
    public static function stem(string $name): string
    {
        $position = strrpos($name, '.');

        return $position === false || $position === 0 ? $name : substr($name, 0, $position);
    }

    /**
     * Every dot separated part after the first one, lower-cased.
     * "shell.PHP.jpg" => ['php', 'jpg'].
     *
     * @return list<string>
     */
    public static function extensionSegments(string $name): array
    {
        $parts = explode('.', strtolower($name));
        array_shift($parts);

        return array_values(array_filter(array_map('trim', $parts), fn ($part) => $part !== ''));
    }
}

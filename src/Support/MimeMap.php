<?php

namespace CodePulse\MediaUpload\Support;

/** @internal */
final class MimeMap
{
    /** @param array<string, list<string>> $map */
    public function __construct(private readonly array $map)
    {
    }

    /** @return list<string> */
    public function extensionsFor(string $mime): array
    {
        return array_map('strtolower', $this->map[$mime] ?? []);
    }

    public function has(string $mime): bool
    {
        return isset($this->map[$mime]);
    }

    public function allows(string $mime, string $extension): bool
    {
        return in_array(strtolower($extension), $this->extensionsFor($mime), true);
    }

    /** @param list<string> $mimes  @return list<string> */
    public function extensionsForAll(array $mimes): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (string $mime) => $this->extensionsFor($mime),
            $mimes ?: ['__none__'],
        ))));
    }

    public function defaultExtension(string $mime): ?string
    {
        return $this->extensionsFor($mime)[0] ?? null;
    }
}

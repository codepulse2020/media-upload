<?php

namespace CodePulse\MediaUpload\Support;

/**
 * Counts animation frames by walking the container structure, without
 * decoding pixels, so frame bombs are refused before any decoder runs.
 *
 * @internal
 */
final class FrameCounter
{
    /** Stop walking after this many frames; the caller only compares to a limit. */
    private const CAP = 10_000;

    public static function count(string $path, string $mime): int
    {
        return match ($mime) {
            'image/gif' => self::gif($path),
            'image/webp' => self::webp($path),
            default => 1,
        };
    }

    private static function gif(string $path): int
    {
        $data = (string) file_get_contents($path);
        $length = strlen($data);

        if ($length < 13) {
            return 1;
        }

        $offset = 13; // header (6) + logical screen descriptor (7)
        $flags = ord($data[10]);

        if ($flags & 0x80) {
            $offset += 3 * (2 ** (($flags & 0x07) + 1)); // global colour table
        }

        $frames = 0;

        while ($offset < $length && $frames < self::CAP) {
            $block = ord($data[$offset]);

            if ($block === 0x3B) { // trailer
                break;
            }

            if ($block === 0x21) { // extension: label + sub-blocks
                $offset = self::skipSubBlocks($data, $offset + 2);

                continue;
            }

            if ($block === 0x2C) { // image descriptor
                $frames++;

                if ($offset + 10 > $length) {
                    break;
                }

                $local = ord($data[$offset + 9]);
                $offset += 10;

                if ($local & 0x80) {
                    $offset += 3 * (2 ** (($local & 0x07) + 1));
                }

                $offset = self::skipSubBlocks($data, $offset + 1); // LZW minimum code size

                continue;
            }

            break; // corrupt; the decoder will refuse it
        }

        return max(1, $frames);
    }

    private static function skipSubBlocks(string $data, int $offset): int
    {
        $length = strlen($data);

        while ($offset < $length) {
            $size = ord($data[$offset]);
            $offset += 1 + $size;

            if ($size === 0) {
                break;
            }
        }

        return $offset;
    }

    private static function webp(string $path): int
    {
        $handle = fopen($path, 'rb');

        if ($handle === false || fread($handle, 12) === false) {
            return 1;
        }

        $frames = 0;

        while (! feof($handle) && $frames < self::CAP) {
            $header = fread($handle, 8);

            if ($header === false || strlen($header) < 8) {
                break;
            }

            $type = substr($header, 0, 4);
            $size = unpack('V', substr($header, 4, 4))[1];

            if ($type === 'ANMF') {
                $frames++;
            }

            if (fseek($handle, $size + ($size & 1), SEEK_CUR) !== 0) {
                break;
            }
        }

        fclose($handle);

        return max(1, $frames);
    }
}

<?php

namespace CodePulse2020\MediaUpload\Support;

use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException as Rejected;

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

    /**
     * Walks the GIF block grammar the decoders use and fails closed: a structure
     * the walk cannot follow exactly (wrong fixed extension sizes, unknown bytes,
     * a block cut short) is refused instead of being under-counted, and every
     * frame must fit inside the logical screen that getimagesize() reports.
     *
     * @throws Rejected
     */
    private static function gif(string $path): int
    {
        $data = (string) file_get_contents($path);
        $length = strlen($data);

        if ($length < 13) {
            throw self::corrupt();
        }

        $screenWidth = self::u16($data, 6);
        $screenHeight = self::u16($data, 8);
        $offset = 13; // header (6) + logical screen descriptor (7)
        $flags = ord($data[10]);

        if ($flags & 0x80) {
            $offset += 3 * (2 ** (($flags & 0x07) + 1)); // global colour table
        }

        $frames = 0;

        while ($frames < self::CAP) {
            if ($offset >= $length) {
                // Missing trailer after complete blocks: decoders stop here too.
                break;
            }

            $block = ord($data[$offset]);

            if ($block === 0x3B) { // trailer
                break;
            }

            if ($block === 0x21) {
                $offset = self::extension($data, $offset);

                continue;
            }

            if ($block === 0x2C) { // image descriptor
                if ($offset + 10 > $length) {
                    throw self::corrupt();
                }

                $left = self::u16($data, $offset + 1);
                $top = self::u16($data, $offset + 3);
                $width = self::u16($data, $offset + 5);
                $height = self::u16($data, $offset + 7);

                // Decoders allocate the frame at its own size, not the screen's.
                if ($left + $width > $screenWidth || $top + $height > $screenHeight) {
                    throw self::corrupt();
                }

                $frames++;
                $local = ord($data[$offset + 9]);
                $offset += 10;

                if ($local & 0x80) {
                    $offset += 3 * (2 ** (($local & 0x07) + 1));
                }

                $offset = self::skipSubBlocks($data, $offset + 1); // after the LZW minimum code size

                continue;
            }

            throw self::corrupt();
        }

        return max(1, $frames);
    }

    /** Extensions with a fixed-size first block must have exactly that size, as the decoders assume. */
    private static function extension(string $data, int $offset): int
    {
        $label = ord($data[$offset + 1] ?? "\0");
        $size = ord($data[$offset + 2] ?? "\0");

        $fixed = match ($label) {
            0xF9 => 4,  // graphic control
            0xFF => 11, // application
            0x01 => 12, // plain text
            default => null,
        };

        if ($fixed !== null && $size !== $fixed) {
            throw self::corrupt();
        }

        if ($label === 0xF9) {
            // Fixed body plus a zero terminator.
            if (($data[$offset + 7] ?? "\1") !== "\0") {
                throw self::corrupt();
            }

            return $offset + 8;
        }

        return self::skipSubBlocks($data, $offset + 2);
    }

    private static function skipSubBlocks(string $data, int $offset): int
    {
        $length = strlen($data);

        while (true) {
            if ($offset >= $length) {
                throw self::corrupt(); // cut short before the block terminator
            }

            $size = ord($data[$offset]);
            $offset += 1 + $size;

            if ($size === 0) {
                return $offset;
            }
        }
    }

    private static function u16(string $data, int $offset): int
    {
        return ord($data[$offset]) | (ord($data[$offset + 1]) << 8);
    }

    private static function corrupt(): Rejected
    {
        return Rejected::because(Rejected::CORRUPT_FILE, 'The image could not be read.');
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

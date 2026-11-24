<?php

namespace CodePulse\MediaUpload\Tests;

use Illuminate\Http\UploadedFile;

/**
 * Builds malicious and benign test files on the fly.
 */
final class Fixtures
{
    private static ?string $dir = null;

    public static function reset(): void
    {
        self::$dir = sys_get_temp_dir().'/media-upload-tests-'.bin2hex(random_bytes(4));
        mkdir(self::$dir);
    }

    public static function cleanup(): void
    {
        if (self::$dir && is_dir(self::$dir)) {
            array_map('unlink', glob(self::$dir.'/*') ?: []);
            rmdir(self::$dir);
        }
    }

    public static function upload(string $contents, string $clientName, string $clientMime = 'application/octet-stream'): UploadedFile
    {
        $path = self::$dir.'/'.bin2hex(random_bytes(6));
        file_put_contents($path, $contents);

        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }

    public static function jpeg(int $width = 200, int $height = 100, string $color = 'red'): string
    {
        $image = imagecreatetruecolor($width, $height);
        $rgb = ['red' => [220, 30, 30], 'blue' => [30, 30, 220], 'black' => [0, 0, 0]][$color];
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagejpeg($image, null, 90);

        return ob_get_clean();
    }

    public static function png(int $width = 120, int $height = 80): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    public static function gif(int $width = 50, int $height = 50): string
    {
        $image = imagecreate($width, $height);
        imagecolorallocate($image, 0, 128, 0);
        ob_start();
        imagegif($image);

        return ob_get_clean();
    }

    /** A semi-transparent white square, used as watermark. */
    public static function watermarkPng(): string
    {
        $image = imagecreatetruecolor(100, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $path = self::$dir.'/watermark.png';
        file_put_contents($path, ob_get_clean());

        return $path;
    }

    /** JPEG with an EXIF APP1 segment carrying a description and camera make. */
    public static function jpegWithExif(string $secret = 'SECRET-GPS-LOCATION'): string
    {
        $jpeg = self::jpeg();
        $entries = [
            [0x010E, $secret."\0"], // ImageDescription
            [0x010F, "EvilCam\0"],  // Make
        ];

        $count = count($entries);
        $dataOffset = 8 + 2 + $count * 12 + 4;
        $ifd = pack('v', $count);
        $data = '';

        foreach ($entries as [$tag, $value]) {
            $ifd .= pack('vvVV', $tag, 2, strlen($value), $dataOffset + strlen($data));
            $data .= $value;
        }

        $tiff = 'II'.pack('v', 42).pack('V', 8).$ifd.pack('V', 0).$data;
        $app1 = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /** Valid GIF with a PHP payload appended after the trailer. */
    public static function gifPolyglot(): string
    {
        return self::gif().'<?php system($_GET["cmd"]); ?>';
    }

    /** JPEG with PHP code hidden in a COM segment. */
    public static function jpegWithPhpComment(): string
    {
        $jpeg = self::jpeg();
        $payload = '<?php eval($_POST["x"]); ?>';
        $segment = "\xFF\xFE".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /** PNG header claiming huge dimensions (a decompression bomb would follow). */
    public static function pngBomb(int $width = 50000, int $height = 50000): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0", 1024)))
            .$chunk('IEND', '');
    }

    public static function svgWithScript(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            .'<script>alert(document.cookie)</script>'
            .'<rect width="10" height="10" fill="red" onload="alert(1)"/>'
            .'</svg>';
    }

    public static function pdfWithJavascript(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    public static function cleanPdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** Animated GIF made by repeating GD's single frame. */
    public static function animatedGif(int $frames): string
    {
        $single = self::gif(20, 20);
        $flags = ord($single[10]);
        $headerLength = 13 + (($flags & 0x80) ? 3 * (2 ** (($flags & 0x07) + 1)) : 0);
        $header = 'GIF89a'.substr($single, 6, $headerLength - 6);
        $frame = substr($single, $headerLength, -1); // without trailer
        $loop = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        $delay = "\x21\xF9\x04\x00\x0A\x00\x00\x00";

        return $header.$loop.str_repeat($delay.$frame, $frames).';';
    }

    /**
     * PNG carrying $secret in a tEXt chunk and in an eXIf chunk (EXIF
     * ImageDescription). eXIf survives Imagick's PNG encoder unless stripped.
     */
    public static function pngWithText(string $secret = 'SECRET-PNG-TEXT'): string
    {
        $png = self::png();
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        $value = $secret."\0";
        $exif = 'II*'."\0".pack('V', 8).pack('v', 1)
            .pack('vvVV', 0x010E, 2, strlen($value), 26).pack('V', 0).$value;

        return substr($png, 0, 33) // signature (8) + IHDR (25)
            .$chunk('tEXt', "Comment\0".$secret)
            .$chunk('eXIf', $exif)
            .substr($png, 33);
    }

    /** Raw file name bytes that are not valid UTF-8 (Big5 for "測試"). */
    public static function big5Name(string $extension = 'jpg'): string
    {
        return "\xB4\xFA\xB8\xD5.".$extension;
    }
}

<?php

namespace CodePulse2020\MediaUpload\Tests;

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

    /** @return array{0: string, 1: string} GIF89a header (with colour table) and one frame block */
    private static function gifParts(int $width, int $height): array
    {
        $single = self::gif($width, $height);
        $flags = ord($single[10]);
        $headerLength = 13 + (($flags & 0x80) ? 3 * (2 ** (($flags & 0x07) + 1)) : 0);

        return ['GIF89a'.substr($single, 6, $headerLength - 6), substr($single, $headerLength, -1)];
    }

    /**
     * Animated GIF whose first graphic control extension declares block size 10
     * (decoders read a fixed 4-byte body), followed by a comment crafted so a
     * sub-block walk loses sync and under-counts the frames.
     */
    public static function gifWithDesyncedExtension(int $frames, int $width = 20, int $height = 20): string
    {
        [$header, $frame] = self::gifParts($width, $height);
        $delay = "\x21\xF9\x04\x00\x0A\x00\x00\x00";

        return $header."\x21\xF9\x0A\x00\x0A\x00\x00\x00"."\x21\xFE\x04AB\x00\x01\x00"
            .$frame.str_repeat($delay.$frame, $frames - 1).';';
    }

    /** Animated GIF with a stray byte between frames (ImageMagick skips it). */
    public static function gifWithJunkByte(int $frames, int $width = 20, int $height = 20): string
    {
        [$header, $frame] = self::gifParts($width, $height);

        return $header.$frame."\x00".str_repeat($frame, $frames - 1).';';
    }

    /** GIF whose logical screen (what getimagesize() reports) is smaller than its frame. */
    public static function gifWithOversizedFrame(int $width, int $height, int $screen = 10): string
    {
        [$header, $frame] = self::gifParts($width, $height);

        return substr($header, 0, 6).pack('vv', $screen, $screen).substr($header, 10).$frame.';';
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

    /**
     * Minimal Office Open XML file.
     *
     * @param  string  $type  xlsx | xlsm | docx | pptx
     * @param  array<string, string>  $extra  additional zip entries
     * @param  bool  $contentTypesLast  write [Content_Types].xml last (some generators do); libmagic then only reports "application/zip"
     */
    public static function ooxml(string $type = 'xlsx', array $extra = [], bool $contentTypesLast = false, ?string $contentTypes = null): string
    {
        if (! class_exists(\ZipArchive::class)) {
            test()->markTestSkipped('ext-zip is not installed.');
        }

        [$part, $main] = [
            'xlsx' => ['/xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'],
            'xlsm' => ['/xl/workbook.xml', 'application/vnd.ms-excel.sheet.macroEnabled.main+xml'],
            'docx' => ['/word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'],
            'pptx' => ['/ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml'],
        ][$type];

        $contentTypes ??= '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="'.$part.'" ContentType="'.$main.'"/>'
            .'</Types>';

        $path = self::$dir.'/'.bin2hex(random_bytes(6)).'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);

        if (! $contentTypesLast) {
            $zip->addFromString('[Content_Types].xml', $contentTypes);
        } else {
            $zip->addFromString('docProps/app.xml', '<?xml version="1.0"?><root/>');
        }

        $zip->addFromString(ltrim($part, '/'), '<?xml version="1.0"?><root/>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="'.ltrim($part, '/').'"/>'
            .'</Relationships>');

        if ($contentTypesLast) {
            $zip->addFromString('[Content_Types].xml', $contentTypes);
        }

        foreach ($extra as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** Report-style PDF that opens at page 1 / fit width: legitimate /OpenAction. */
    public static function pdfWithOpenAction(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R /OpenAction [3 0 R /Fit] >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** JavaScript hidden behind PDF name escapes: /J#61vaScript == /JavaScript. */
    public static function pdfWithEscapedJavascript(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /OpenAction << /S /J#61vaScript /J#53 (app.alert(1)) >> >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * xlsx whose part really decompresses to $realBytes but whose zip headers
     * claim it is 100 bytes (sizes in the local header and central directory
     * are uploader controlled).
     */
    public static function ooxmlWithLyingSize(int $realBytes = 3 * 1024 * 1024 + 7): string
    {
        $bytes = self::ooxml('xlsx', ['xl/big.xml' => str_repeat('A', $realBytes)]);
        $real = pack('V', $realBytes);

        if (substr_count($bytes, $real) !== 2) {
            throw new \RuntimeException('Could not locate the size fields to patch.');
        }

        return str_replace($real, pack('V', 100), $bytes);
    }

    /** [Content_Types].xml in UTF-16 with an internal DTD and entity. */
    public static function utf16ContentTypesWithDoctype(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-16"?>'
            .'<!DOCTYPE Types [<!ENTITY t "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml">]>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Override PartName="/xl/workbook.xml" ContentType="&t;"/></Types>';

        return "\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
    }
}

<?php

namespace CodePulse\MediaUpload\Scanning;

use CodePulse\MediaUpload\Contracts\VirusScanner;
use CodePulse\MediaUpload\Exceptions\ScanLimitExceededException;
use CodePulse\MediaUpload\Exceptions\ScannerUnavailableException;

/**
 * Talks to clamd using the INSTREAM command.
 * socket: "unix:///var/run/clamav/clamd.ctl" or "tcp://127.0.0.1:3310".
 * $maxBytes must not exceed clamd's StreamMaxLength (25 MB by default).
 *
 * @api
 */
final class ClamAvScanner implements VirusScanner
{
    private const CHUNK = 8192;

    public function __construct(
        private readonly string $socket = 'unix:///var/run/clamav/clamd.ctl',
        private readonly int $timeout = 30,
        private readonly int $maxBytes = 25 * 1024 * 1024,
    ) {
    }

    public function scan($stream): ?string
    {
        $size = fstat($stream)['size'] ?? null;

        if (is_int($size) && $size > $this->maxBytes) {
            throw new ScanLimitExceededException("File of {$size} bytes exceeds the scan limit of {$this->maxBytes} bytes.");
        }

        $connection = @stream_socket_client($this->socket, $errno, $error, $this->timeout);

        if ($connection === false) {
            throw new ScannerUnavailableException("Cannot connect to clamd at {$this->socket}: {$error}");
        }

        stream_set_timeout($connection, $this->timeout);

        try {
            $this->write($connection, "zINSTREAM\0");
            $sent = 0;

            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $sent += strlen($chunk);

                if ($sent > $this->maxBytes) {
                    throw new ScanLimitExceededException("File exceeds the scan limit of {$this->maxBytes} bytes.");
                }

                if (! $this->write($connection, pack('N', strlen($chunk)).$chunk, required: false)) {
                    // clamd closes the stream when its own limit is hit; read why.
                    $this->interpret($this->read($connection));
                    throw new ScannerUnavailableException('Lost connection to clamd while streaming.');
                }
            }

            $this->write($connection, pack('N', 0));

            return $this->interpret($this->read($connection));
        } finally {
            fclose($connection);
        }
    }

    /**
     * "stream: OK" | "stream: Eicar-Signature FOUND" | "INSTREAM size limit exceeded. ERROR"
     */
    private function interpret(string $response): ?string
    {
        if (stripos($response, 'size limit') !== false) {
            throw new ScanLimitExceededException('clamd refused the file: '.$response);
        }

        if (str_ends_with($response, 'OK')) {
            return null;
        }

        if (str_ends_with($response, 'FOUND')) {
            return trim(substr($response, (int) strpos($response, ':') + 1, -strlen('FOUND')));
        }

        throw new ScannerUnavailableException("Unexpected clamd response: {$response}");
    }

    /** @param resource $connection */
    private function read($connection): string
    {
        return trim((string) stream_get_contents($connection), "\0\r\n ");
    }

    /** @param resource $connection */
    private function write($connection, string $data, bool $required = true): bool
    {
        $ok = @fwrite($connection, $data) === strlen($data);

        if (! $ok && $required) {
            throw new ScannerUnavailableException('Lost connection to clamd while streaming.');
        }

        return $ok;
    }
}

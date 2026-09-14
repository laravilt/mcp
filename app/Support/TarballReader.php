<?php

namespace App\Support;

use RuntimeException;

/**
 * A small streaming reader for (gzipped) tar archives such as the tarballs
 * served by the GitHub API. It understands ustar prefixes, PAX extended
 * headers and GNU long names, and only buffers the entries you ask for.
 */
class TarballReader
{
    /**
     * Iterate over every entry of the archive.
     *
     * The callback receives the entry path (with the top-level directory that
     * GitHub adds stripped), its size and type ("file" or "dir"), and returns
     * true when the file contents should be read. Selected files are then
     * yielded as path => contents.
     *
     * @param  callable(string, int, string): bool  $wants
     * @return array{files: array<string, string>, paths: list<string>}
     */
    public function read(string $archivePath, callable $wants, bool $stripRoot = true): array
    {
        $handle = @gzopen($archivePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open archive [{$archivePath}].");
        }

        $files = [];
        $paths = [];
        $longName = null;
        $paxPath = null;

        try {
            while (true) {
                $header = $this->readBytes($handle, 512);

                if ($header === '' || strlen($header) < 512) {
                    break;
                }

                if (trim($header, "\0") === '') {
                    // End of archive marker (two zero blocks).
                    break;
                }

                $name = $this->field($header, 0, 100);
                $size = $this->octal(substr($header, 124, 12));
                $type = $header[156];
                $prefix = $this->field($header, 345, 155);
                $magic = substr($header, 257, 5);

                if ($magic === 'ustar' && $prefix !== '') {
                    $name = $prefix.'/'.$name;
                }

                $padding = (512 - ($size % 512)) % 512;

                if ($type === 'x' || $type === 'g' || $type === 'L') {
                    $data = $this->readBytes($handle, $size);
                    $this->readBytes($handle, $padding);

                    if ($type === 'L') {
                        $longName = rtrim($data, "\0");
                    } elseif ($type === 'x') {
                        $paxPath = $this->paxValue($data, 'path') ?? $paxPath;
                    }

                    continue;
                }

                if ($longName !== null) {
                    $name = $longName;
                }

                if ($paxPath !== null) {
                    $name = $paxPath;
                }

                $longName = null;
                $paxPath = null;

                $entryType = match ($type) {
                    '5' => 'dir',
                    '0', "\0", '7' => 'file',
                    default => 'other',
                };

                $path = $this->normalizePath($name, $stripRoot);

                if ($path !== '' && $entryType !== 'other') {
                    $paths[] = $entryType === 'dir' ? rtrim($path, '/').'/' : $path;
                }

                if ($entryType === 'file' && $path !== '' && $wants($path, $size, $entryType)) {
                    $files[$path] = $this->readBytes($handle, $size);
                    $this->readBytes($handle, $padding);
                } else {
                    $this->skipBytes($handle, $size + $padding);
                }
            }
        } finally {
            gzclose($handle);
        }

        return ['files' => $files, 'paths' => $paths];
    }

    /**
     * @param  resource  $handle
     */
    protected function readBytes($handle, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $buffer = '';

        while (strlen($buffer) < $length && ! gzeof($handle)) {
            $chunk = gzread($handle, min(1 << 16, $length - strlen($buffer)));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param  resource  $handle
     */
    protected function skipBytes($handle, int $length): void
    {
        while ($length > 0 && ! gzeof($handle)) {
            $chunk = gzread($handle, min(1 << 16, $length));

            if ($chunk === false || $chunk === '') {
                return;
            }

            $length -= strlen($chunk);
        }
    }

    protected function field(string $header, int $offset, int $length): string
    {
        $value = substr($header, $offset, $length);
        $end = strpos($value, "\0");

        return $end === false ? $value : substr($value, 0, $end);
    }

    protected function octal(string $value): int
    {
        // Base-256 encoding (first bit set) is only used for files above 8 GiB.
        if ($value !== '' && (ord($value[0]) & 0x80) !== 0) {
            $size = 0;

            for ($i = 1; $i < strlen($value); $i++) {
                $size = ($size << 8) | ord($value[$i]);
            }

            return $size;
        }

        $value = trim($value, " \0");

        return $value === '' ? 0 : (int) octdec($value);
    }

    protected function paxValue(string $data, string $key): ?string
    {
        $offset = 0;

        while ($offset < strlen($data)) {
            $space = strpos($data, ' ', $offset);

            if ($space === false) {
                break;
            }

            $length = (int) substr($data, $offset, $space - $offset);

            if ($length <= 0) {
                break;
            }

            $record = substr($data, $space + 1, $length - ($space - $offset) - 2);
            [$recordKey, $value] = array_pad(explode('=', $record, 2), 2, '');

            if ($recordKey === $key) {
                return $value;
            }

            $offset += $length;
        }

        return null;
    }

    protected function normalizePath(string $name, bool $stripRoot): string
    {
        $name = str_replace('\\', '/', $name);

        while (str_starts_with($name, './') || str_starts_with($name, '/')) {
            $name = str_starts_with($name, './') ? substr($name, 2) : substr($name, 1);
        }

        if ($name === 'pax_global_header') {
            return '';
        }

        if ($stripRoot) {
            $slash = strpos($name, '/');
            $name = $slash === false ? '' : substr($name, $slash + 1);
        }

        // Guard against path traversal in hostile archives.
        if (str_contains('/'.$name.'/', '/../')) {
            return '';
        }

        return $name;
    }
}

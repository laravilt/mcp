<?php

namespace Tests\Support;

/**
 * Builds GitHub-style gzipped tarballs in memory for tests.
 */
class Tarball
{
    /**
     * @param  array<string, string>  $files  path => contents (a trailing "/" marks a directory)
     */
    public static function make(array $files, string $root = 'laravilt-repo-abc1234', ?string $commit = null): string
    {
        $tar = '';

        // GitHub adds a PAX global header carrying the commit SHA.
        $tar .= static::entry('pax_global_header', static::pax(['comment' => $commit ?? str_repeat('a', 40)]), 'g');
        $tar .= static::entry($root.'/', '', '5');

        foreach ($files as $path => $contents) {
            $name = $root.'/'.$path;

            if (str_ends_with($path, '/')) {
                $tar .= static::entry($name, '', '5');

                continue;
            }

            if (strlen($name) > 100) {
                $tar .= static::entry('PaxHeader', static::pax(['path' => $name]), 'x');
                $name = substr($name, 0, 100);
            }

            $tar .= static::entry($name, $contents, '0');
        }

        $tar .= str_repeat("\0", 1024);

        return gzencode($tar);
    }

    protected static function entry(string $name, string $contents, string $type): string
    {
        $header = str_pad($name, 100, "\0");
        $header .= str_pad('0000644', 7, '0', STR_PAD_LEFT)."\0";
        $header .= str_pad('0000000', 7, '0', STR_PAD_LEFT)."\0";
        $header .= str_pad('0000000', 7, '0', STR_PAD_LEFT)."\0";
        $header .= str_pad(decoct(strlen($contents)), 11, '0', STR_PAD_LEFT)."\0";
        $header .= str_pad(decoct(1_700_000_000), 11, '0', STR_PAD_LEFT)."\0";
        $header .= '        '; // checksum placeholder
        $header .= $type;
        $header .= str_repeat("\0", 100); // linkname
        $header .= "ustar\0".'00';
        $header .= str_pad('git', 32, "\0");
        $header .= str_pad('git', 32, "\0");
        $header .= str_repeat("\0", 16); // devmajor, devminor
        $header .= str_repeat("\0", 155); // prefix
        $header = str_pad($header, 512, "\0");

        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);

        $padding = (512 - (strlen($contents) % 512)) % 512;

        return $header.$contents.str_repeat("\0", $padding);
    }

    /**
     * @param  array<string, string>  $records
     */
    protected static function pax(array $records): string
    {
        $data = '';

        foreach ($records as $key => $value) {
            $record = " {$key}={$value}\n";
            $length = strlen($record);
            $length += strlen((string) ($length + strlen((string) $length)));
            $data .= $length.$record;
        }

        return $data;
    }
}

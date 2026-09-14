<?php

namespace App\Services\Packagist;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class PackagistClient
{
    public function __construct(protected string $url = 'https://repo.packagist.org')
    {
        //
    }

    public static function fromConfig(): self
    {
        return new self(rtrim((string) config('laravilt.packagist.url'), '/'));
    }

    /**
     * Tagged versions of a package, newest first. Returns null when the
     * package is not published on Packagist.
     *
     * @return list<array{version: string, normalized: string, time: ?string, url: ?string, stable: bool}>|null
     *
     * @throws RequestException|ConnectionException
     */
    public function versions(string $name): ?array
    {
        $response = Http::timeout(30)
            ->withUserAgent('laravilt-mcp (+https://mcp.laravilt.com)')
            ->acceptJson()
            ->get("{$this->url}/p2/{$name}.json");

        if ($response->notFound()) {
            return null;
        }

        $entries = $response->throw()->json("packages.{$name}");

        if (! is_array($entries)) {
            return [];
        }

        $versions = [];

        foreach ($this->expand($entries) as $entry) {
            $version = (string) ($entry['version'] ?? '');

            if ($version === '' || str_starts_with($version, 'dev-') || str_ends_with($version, '-dev')) {
                continue;
            }

            $versions[] = [
                'version' => ltrim($version, 'vV'),
                'normalized' => (string) ($entry['version_normalized'] ?? ltrim($version, 'vV')),
                'time' => $entry['time'] ?? null,
                'url' => $entry['support']['source'] ?? null,
                'stable' => ! preg_match('/(alpha|beta|rc|dev|patch)/i', $version),
            ];
        }

        usort($versions, fn (array $a, array $b): int => version_compare($b['normalized'], $a['normalized']));

        return $versions;
    }

    /**
     * Expand Composer 2 "minified" metadata, where every entry only lists the
     * keys that changed compared to the previous one.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    protected function expand(array $entries): array
    {
        $expanded = [];
        $previous = [];

        foreach ($entries as $entry) {
            foreach ($entry as $key => $value) {
                if ($value === '__unset') {
                    unset($previous[$key]);
                } else {
                    $previous[$key] = $value;
                }
            }

            $expanded[] = $previous;
        }

        return $expanded;
    }

    /**
     * The newest stable version, falling back to the newest pre-release.
     *
     * @param  list<array{version: string, normalized: string, time: ?string, url: ?string, stable: bool}>  $versions
     * @return array{version: string, normalized: string, time: ?string, url: ?string, stable: bool}|null
     */
    public static function latest(array $versions): ?array
    {
        foreach ($versions as $version) {
            if ($version['stable']) {
                return $version;
            }
        }

        return $versions[0] ?? null;
    }
}

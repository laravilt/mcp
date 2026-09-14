<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Catalog;
use App\Models\Package;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-latest-versions')]
#[Title('Get latest Laravilt versions')]
#[Description('Get a compact map of every Laravilt package to its latest released version (from Packagist and GitHub releases) plus ready-to-paste composer require lines. Use it before writing composer.json constraints or upgrade instructions so versions are never guessed.')]
#[IsReadOnly]
#[IsIdempotent]
class GetLatestVersions extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $packages = Catalog::packages();

        if ($packages->isEmpty()) {
            return Catalog::emptyIndexResponse();
        }

        return Response::make(Response::text(static::markdown()))
            ->withStructuredContent(['versions' => static::versions()]);
    }

    /**
     * @return array<string, string|null>
     */
    public static function versions(): array
    {
        return Catalog::packages()
            ->mapWithKeys(fn (Package $package): array => [$package->name => $package->latest_version])
            ->all();
    }

    public static function markdown(): string
    {
        $packages = Catalog::packages();
        $released = $packages->filter(fn (Package $package) => $package->latest_version !== null);
        $meta = $packages->firstWhere('name', Catalog::META_PACKAGE);

        $all = $released
            ->reject(fn (Package $package) => $package->name === Catalog::META_PACKAGE)
            ->map(fn (Package $package): string => $package->name.':^'.$package->latest_version)
            ->implode(' ');

        $lines = [
            '# Latest Laravilt versions',
            '',
            Catalog::lastSyncLine(),
            '',
            '```json',
            json_encode(static::versions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '```',
            '',
            'Install the whole framework (pulls in every core package):',
            '',
            '```bash',
            'composer require '.Catalog::META_PACKAGE.($meta?->latest_version ? ':^'.$meta->latest_version : ''),
            '```',
        ];

        if ($all !== '') {
            array_push($lines, '', 'Or pin every package to its latest release:', '', '```bash', 'composer require '.$all, '```');
        }

        return implode("\n", $lines);
    }
}

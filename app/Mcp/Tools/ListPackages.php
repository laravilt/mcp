<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Catalog;
use App\Models\Package;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-packages')]
#[Title('List Laravilt packages')]
#[Description('List every Laravilt package (laravilt/*) with its description, latest released version, last release date, composer install command and GitHub repository URL. Use this first to discover which packages exist before calling get-package, list-docs or search-docs.')]
#[IsReadOnly]
#[IsIdempotent]
class ListPackages extends Tool
{
    public function handle(Request $request): Response
    {
        $packages = Catalog::packages();

        if ($packages->isEmpty()) {
            return Catalog::emptyIndexResponse();
        }

        $sections = $packages->map(fn (Package $package): string => implode("\n", [
            '## '.$package->name.' — '.Catalog::version($package)
                .($package->latest_release_at ? ' ('.Catalog::date($package->latest_release_at).')' : ''),
            '',
            Str::limit((string) $package->description, 300),
            '',
            '- Install: `'.$package->installCommand().'`',
            '- Repository: '.$package->repo_url,
            '- Frontend: '.$package->frontendSummary().' · Docs: '.$package->documents_count.' files · Tool argument: `"package": "'.$package->repo.'"`',
        ]));

        return Response::text(
            '# Laravilt packages ('.$packages->count().")\n\n"
            .Catalog::lastSyncLine()."\n\n"
            ."Install the full framework with `composer require laravilt/laravilt` followed by `php artisan laravilt:install`.\n\n"
            .$sections->implode("\n\n")
        );
    }
}

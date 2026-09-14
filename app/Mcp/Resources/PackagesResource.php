<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\Catalog;
use App\Mcp\Tools\GetLatestVersions;
use App\Models\Package;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('laravilt-packages')]
#[Title('Laravilt packages and versions')]
#[Description('Every Laravilt package with its latest version, release date and repository, plus composer require lines.')]
#[Uri('laravilt://packages')]
#[MimeType('text/markdown')]
class PackagesResource extends Resource
{
    public function handle(Request $request): Response
    {
        $packages = Catalog::packages();

        if ($packages->isEmpty()) {
            return Response::text("# Laravilt packages\n\n_The server has not synced yet._");
        }

        $rows = $packages->map(fn (Package $package): string => '| `'.$package->name.'` | '.Catalog::version($package)
            .' | '.($package->latest_release_at ? Catalog::date($package->latest_release_at) : '—')
            .' | '.$package->frontendSummary()
            .' | '.$package->repo_url.' |');

        return Response::text(
            GetLatestVersions::markdown()
            ."\n\n## Packages\n\n| Package | Version | Released | Frontend | Repository |\n|---|---|---|---|---|\n"
            .$rows->implode("\n")
        );
    }
}

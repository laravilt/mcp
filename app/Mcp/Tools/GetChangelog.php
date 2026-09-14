<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Catalog;
use App\Models\Package;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-changelog')]
#[Title('Get a Laravilt package changelog')]
#[Description('Get the release history of a Laravilt package: tagged versions with release dates and GitHub release notes, followed by entries from its CHANGELOG.md. Useful for upgrade notes and for checking when a feature or fix shipped.')]
#[IsReadOnly]
#[IsIdempotent]
class GetChangelog extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()
                ->description('Package name, e.g. "panel" or "laravilt/forms".')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of releases and changelog entries to include (1-50, default 10).')
                ->min(1)
                ->max(50),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'package' => ['required', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $package = Package::findByIdentifier($validated['package']);

        if ($package === null) {
            return Package::query()->exists()
                ? Catalog::unknownPackageResponse($validated['package'])
                : Catalog::emptyIndexResponse();
        }

        return Response::text(
            '# '.$package->name.' changelog'."\n\n"
            .'- Latest version: '.Catalog::version($package)
            .($package->latest_release_at ? ' ('.Catalog::date($package->latest_release_at).')' : '')."\n"
            .'- Releases: '.$package->repo_url.'/releases'."\n\n"
            .Catalog::changelog($package, (int) ($validated['limit'] ?? 10))
        );
    }
}

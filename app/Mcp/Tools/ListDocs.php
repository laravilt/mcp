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

#[Name('list-docs')]
#[Title('List Laravilt documentation files')]
#[Description('Show the documentation tree (README, CHANGELOG, composer.json and every Markdown file under docs/) for one Laravilt package with document titles, or a compact tree for all packages when no package is given. The laravilt/laravilt meta package holds the main framework guide.')]
#[IsReadOnly]
#[IsIdempotent]
class ListDocs extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()
                ->description('Optional package name, e.g. "laravilt" (main docs), "panel" or "plugins". Omit to list every package.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! Package::query()->exists()) {
            return Catalog::emptyIndexResponse();
        }

        $identifier = $request->string('package')->trim()->toString();

        if ($identifier !== '') {
            $package = Package::findByIdentifier($identifier);

            if ($package === null) {
                return Catalog::unknownPackageResponse($identifier);
            }

            $documents = $package->documents()->orderBy('path')->get(['path', 'title']);

            return Response::text(
                '# '.$package->name.' documentation ('.$documents->count()." files)\n\n"
                .Catalog::tree($documents->pluck('path'), $documents->pluck('title', 'path')->all())
                ."\n\nRead a file with `read-doc {\"package\":\"{$package->repo}\",\"path\":\"<path>\"}`."
            );
        }

        $sections = Package::query()->ordered()->with(['documents' => fn ($query) => $query->select(['id', 'package_id', 'path'])->orderBy('path')])->get()
            ->map(fn (Package $package): string => '## '.$package->name.' ('.$package->documents->count()." files)\n\n"
                .Catalog::tree($package->documents->pluck('path')));

        return Response::text(
            "# Laravilt documentation\n\n"
            ."Pass `package` to see document titles for a single package.\n\n"
            .$sections->implode("\n\n")
        );
    }
}

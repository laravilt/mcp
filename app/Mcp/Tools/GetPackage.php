<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Catalog;
use App\Models\Document;
use App\Models\Package;
use App\Support\Markdown;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-package')]
#[Title('Get a Laravilt package')]
#[Description('Get details about one Laravilt package: README summary, latest version and install command, requirements (PHP, Laravel and other laravilt/* dependencies from composer.json), frontend support (Vue in resources/js, React in resources/react), its documentation index and the most recent changelog/release entries.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPackage extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()
                ->description('Package name, e.g. "panel", "forms" or "laravilt/tables". Use list-packages to see all names.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $package = Package::findByIdentifier($request->string('package')->toString());

        if ($package === null) {
            return Package::query()->exists()
                ? Catalog::unknownPackageResponse($request->get('package'))
                : Catalog::emptyIndexResponse();
        }

        $readme = $package->documents()->where('path', 'README.md')->first();
        $requirements = Catalog::requirements($package);

        $requirementLines = array_filter([
            $requirements['php'] ? '- **PHP:** `'.$requirements['php'].'`' : null,
            $requirements['laravel'] ? '- **Laravel:** `'.$requirements['laravel'].'`' : null,
            $requirements['laravilt'] !== []
                ? '- **Laravilt packages:** '.collect($requirements['laravilt'])->map(fn ($c, $n) => "`{$n}` {$c}")->implode(', ')
                : '- **Laravilt packages:** none',
            $requirements['other'] !== []
                ? '- **Other:** '.collect($requirements['other'])->map(fn ($c, $n) => "`{$n}` {$c}")->implode(', ')
                : null,
        ]);

        $documents = $package->documents()->orderBy('path')->get(['id', 'package_id', 'path', 'title']);
        $docs = $documents
            ->filter(fn (Document $document) => str_starts_with($document->path, 'docs/') || $document->path === 'README.md')
            ->take(60)
            ->map(fn (Document $document): string => '- `'.$document->path.'` — '.$document->title)
            ->implode("\n");

        $more = $documents->count() > 60 ? "\n- … use `list-docs {\"package\":\"{$package->repo}\"}` for the full tree." : '';

        $markdown = implode("\n", [
            '# '.$package->name,
            '',
            '> '.($package->description ?: 'No description.'),
            '',
            '- **Latest version:** '.Catalog::version($package).($package->latest_release_at ? ' (released '.Catalog::date($package->latest_release_at).')' : ''),
            '- **Install:** `'.$package->installCommand().'`',
            '- **Repository:** '.$package->repo_url,
            '- **Synced commit:** `'.substr((string) $package->sha, 0, 7).'` on `'.$package->default_branch.'` ('.Catalog::date($package->content_synced_at, true).')',
            '',
            '## Summary',
            '',
            $readme ? (Markdown::summary($readme->content, 900) ?: '_README has no prose summary._')."\n\n[README]({$readme->source_url})" : '_No README._',
            '',
            '## Requirements',
            '',
            implode("\n", $requirementLines),
            '',
            '## Frontend',
            '',
            Catalog::frontendNotes($package),
            '',
            '## Documentation ('.$documents->count().' files)',
            '',
            ($docs ?: '_No documentation files._').$more,
            '',
            'Read a file with `read-doc {"package":"'.$package->repo.'","path":"<path>"}`.',
            '',
            '## Recent changes',
            '',
            Catalog::changelog($package, 3),
        ]);

        return Response::text($markdown);
    }
}

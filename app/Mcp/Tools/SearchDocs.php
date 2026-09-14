<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Catalog;
use App\Models\Package;
use App\Services\Search\DocumentSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search-docs')]
#[Title('Search Laravilt documentation')]
#[Description('Full-text search across the documentation, READMEs and changelogs of all Laravilt packages. Returns ranked snippets with the package, file path and GitHub link. Use short keyword queries (e.g. "relation manager", "TextInput validation", "tenancy"), then open a hit with read-doc.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchDocs extends Tool
{
    public function __construct(protected DocumentSearch $search)
    {
        //
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Keywords to search for.')
                ->required(),
            'package' => $schema->string()
                ->description('Optional package to restrict the search to, e.g. "forms" or "laravilt/panel".'),
            'limit' => $schema->integer()
                ->description('Maximum number of results (1-'.config('laravilt.search.max_limit', 25).', default 8).')
                ->min(1)
                ->max((int) config('laravilt.search.max_limit', 25)),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:300'],
            'package' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('laravilt.search.max_limit', 25)],
        ], [
            'query.required' => 'Provide a "query", for example {"query": "table filters"}.',
        ]);

        if (! Package::query()->exists()) {
            return Catalog::emptyIndexResponse();
        }

        $package = null;

        if (filled($validated['package'] ?? null)) {
            $package = Package::findByIdentifier($validated['package']);

            if ($package === null) {
                return Catalog::unknownPackageResponse($validated['package']);
            }
        }

        $query = trim($validated['query']);
        $results = $this->search->search($query, $package?->id, (int) ($validated['limit'] ?? 8));
        $scope = $package ? " in {$package->name}" : '';

        if ($results === []) {
            return Response::text("No documentation matched \"{$query}\"{$scope}. Try fewer or different keywords, or browse with list-docs.");
        }

        $lines = ['# Search results for "'.$query.'"'.$scope.' ('.count($results).')', ''];

        foreach ($results as $index => $result) {
            $document = $result['document'];

            $lines[] = ($index + 1).'. **'.$document->title.'** — `'.$document->package->name.'` · `'.$document->path.'`';
            $lines[] = '   > '.$result['snippet'];
            $lines[] = '   [GitHub]('.$document->source_url.') · '.Catalog::readDocHint($document->package, $document->path);
            $lines[] = '';
        }

        return Response::text(rtrim(implode("\n", $lines)));
    }
}

<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Catalog;
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

#[Name('read-doc')]
#[Title('Read a Laravilt document')]
#[Description('Read a full documentation file from a Laravilt package, e.g. {"package":"laravilt","path":"docs/getting-started/installation.md"}. Paths come from search-docs, list-docs or get-package. Long documents are split into pages; pass "page" to continue.')]
#[IsReadOnly]
#[IsIdempotent]
class ReadDoc extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()
                ->description('Package name, e.g. "panel" or "laravilt/forms".')
                ->required(),
            'path' => $schema->string()
                ->description('File path inside the package repository, e.g. "README.md", "CHANGELOG.md" or "docs/resources/introduction.md".')
                ->required(),
            'page' => $schema->integer()
                ->description('Page number for long documents (default 1).')
                ->min(1),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'package' => ['required', 'string', 'max:100'],
            'path' => ['required', 'string', 'max:500'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $package = Package::findByIdentifier($validated['package']);

        if ($package === null) {
            return Package::query()->exists()
                ? Catalog::unknownPackageResponse($validated['package'])
                : Catalog::emptyIndexResponse();
        }

        $document = Catalog::findDocument($package, $validated['path']);

        if ($document === null) {
            $suggestions = Catalog::similarPaths($package, $validated['path'])->map(fn ($path) => "`{$path}`")->implode(', ');

            return Response::error("Document [{$validated['path']}] was not found in {$package->name}. Did you mean: {$suggestions}?");
        }

        $content = str_ends_with($document->path, '.json')
            ? "```json\n".trim($document->content)."\n```"
            : $document->content;

        $pages = Markdown::paginate($content, (int) config('laravilt.docs.page_size', 16000));
        $total = count($pages);
        $page = (int) ($validated['page'] ?? 1);

        if ($page > $total) {
            return Response::error("Page {$page} does not exist; [{$document->path}] has {$total} page(s).");
        }

        $header = '# '.$document->title."\n\n"
            .'_'.$package->name.' · `'.$document->path.'` · [GitHub]('.$document->source_url.')'
            .' · commit `'.substr((string) $document->sha, 0, 7).'`'
            .($total > 1 ? " · page {$page} of {$total}" : '').'_';

        $footer = $page < $total
            ? "\n\n---\n_Continue with ".Catalog::readDocHint($package, $document->path, $page + 1).'._'
            : '';

        return Response::text($header."\n\n---\n\n".rtrim($pages[$page - 1]).$footer);
    }
}

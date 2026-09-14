<?php

namespace App\Mcp\Methods;

use App\Mcp\Support\DocumentUriTemplate;
use App\Models\Document;
use Illuminate\Support\Collection;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Pagination\CursorPaginator;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * resources/list that includes one concrete resource per synced document
 * (laravilt://docs/{package}/{path}) after the static resources.
 */
class ListResources implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $documents = Document::query()
            ->join('packages', 'packages.id', '=', 'documents.package_id')
            ->orderByRaw("CASE WHEN packages.name = 'laravilt/laravilt' THEN 0 ELSE 1 END")
            ->orderBy('packages.name')
            ->orderBy('documents.path')
            ->selectRaw('documents.path, documents.title, packages.repo, packages.name AS package_name, LENGTH(documents.content) AS size')
            ->get()
            ->map(fn (Document $document): array => [
                'name' => $document->getAttribute('repo').'/'.$document->path,
                'title' => $document->title,
                'description' => $document->getAttribute('package_name').': '.$document->title,
                'uri' => DocumentUriTemplate::uri((string) $document->getAttribute('repo'), $document->path),
                'mimeType' => str_ends_with($document->path, '.json') ? 'application/json' : 'text/markdown',
                'size' => (int) $document->getAttribute('size'),
            ]);

        /** @var Collection<int, mixed> $items */
        $items = $context->resources()->values()->concat($documents);

        $paginator = new CursorPaginator(
            items: $items,
            perPage: $context->perPage($request->get('per_page')),
            cursor: $request->cursor(),
        );

        return JsonRpcResponse::result($request->id, $paginator->paginate('resources'));
    }
}

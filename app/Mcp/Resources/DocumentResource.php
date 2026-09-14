<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\Catalog;
use App\Mcp\Support\DocumentUriTemplate;
use App\Models\Package;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Name('laravilt-doc')]
#[Title('Laravilt document')]
#[Description('A documentation file from a Laravilt package, e.g. laravilt://docs/laravilt/docs/getting-started/installation.md or laravilt://docs/panel/README.md.')]
#[MimeType('text/markdown')]
class DocumentResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new DocumentUriTemplate;
    }

    public function handle(Request $request): Response
    {
        $package = Package::findByIdentifier((string) $request->get('package'));

        if ($package === null) {
            return Response::error("Unknown Laravilt package [{$request->get('package')}].");
        }

        $document = Catalog::findDocument($package, (string) $request->get('path'));

        if ($document === null) {
            return Response::error("Document [{$request->get('path')}] was not found in {$package->name}.");
        }

        return Response::text($document->content)->withMeta([
            'package' => $package->name,
            'path' => $document->path,
            'title' => $document->title,
            'sourceUrl' => $document->source_url,
            'sha' => $document->sha,
        ]);
    }
}

<?php

namespace App\Mcp\Prompts;

use App\Mcp\Support\Catalog;
use App\Models\Package;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('build-resource')]
#[Title('Build a Laravilt panel resource')]
#[Description('Guide the assistant through generating a Laravilt panel resource (form, table, pages) for an Eloquent model, grounded in the latest Laravilt documentation.')]
class BuildResource extends Prompt
{
    public function arguments(): array
    {
        return [
            new Argument('model', 'The Eloquent model to build a resource for, e.g. "Post" or "App\\Models\\Invoice".', required: true),
            new Argument('panel', 'The panel id to register the resource in (default "admin").'),
            new Argument('stack', 'Frontend stack of the app: "vue" or "react" (default "vue").'),
            new Argument('fields', 'Optional comma separated list of attributes to include, e.g. "title, body, status, published_at".'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'model' => ['required', 'string', 'max:150'],
            'panel' => ['nullable', 'string', 'max:50'],
            'stack' => ['nullable', 'in:vue,react'],
            'fields' => ['nullable', 'string', 'max:1000'],
        ]);

        $model = class_basename(str_replace('/', '\\', $validated['model']));
        $panel = $validated['panel'] ?? 'admin';
        $stack = $validated['stack'] ?? 'vue';
        $meta = Catalog::metaPackage();

        $context = collect([
            [Catalog::META_PACKAGE, ['docs/getting-started/first-resource.md'], 7000],
            // The docs moved folder introductions to README.md; older releases still have introduction.md.
            [Catalog::META_PACKAGE, ['docs/panel/resources/README.md', 'docs/panel/resources/introduction.md'], 5000],
        ])->map(function (array $candidate): ?string {
            [$package, $paths, $limit] = $candidate;
            $document = Catalog::firstDocument(array_map(fn (string $path): array => [$package, $path], $paths));

            return $document ? Catalog::embed($document, 2, $limit) : null;
        })->filter()->implode("\n\n");

        $fields = filled($validated['fields'] ?? null)
            ? 'Include these attributes: '.$validated['fields'].'.'
            : "Inspect the `{$model}` model, its migration and casts to decide which attributes to include.";

        $text = <<<MD
        Build a Laravilt panel resource for the `{$model}` Eloquent model in the `{$panel}` panel of a Laravel 13 app that uses the **{$stack}** frontend stack.

        Laravilt version: {$this->version($meta)}.

        Follow these steps:

        1. Generate the resource with the Laravilt generator (check the command and its options in the docs below), e.g. `php artisan laravilt:resource {$panel} --model={$model}`.
        2. Define the form schema. {$fields} Pick field types that match the column types (text inputs, selects for enums/relations, toggles for booleans, date pickers for dates) and add validation rules.
        3. Define the table: searchable/sortable columns, filters for status or date columns, and row plus bulk actions (edit, delete).
        4. Register the list/create/edit (and view if useful) pages and navigation (icon, group, sort).
        5. Keep the PHP side stack-agnostic; the {$stack} components are provided by the Laravilt packages installed with `php artisan laravilt:install --stack={$stack}`.
        6. Before using any class, method or option you are not sure about, verify it with the Laravilt MCP tools: `search-docs` (e.g. {"query": "TextInput", "package": "forms"}), `read-doc`, and `get-latest-versions`. Do not invent APIs.

        Reference documentation (latest synced version):

        {$context}
        MD;

        return Response::text(Str::of($text)->trim()->toString());
    }

    protected function version(?Package $meta): string
    {
        return $meta ? 'laravilt/laravilt '.Catalog::version($meta) : 'unknown (not synced yet)';
    }
}

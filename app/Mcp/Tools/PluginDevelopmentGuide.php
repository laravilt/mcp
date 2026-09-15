<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Catalog;
use App\Models\Document;
use App\Models\Package;
use App\Support\Markdown;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('plugin-development-guide')]
#[Title('Laravilt plugin development guide')]
#[Description('An up-to-date guide to building Laravilt plugins, assembled from the latest laravilt/plugins README and docs plus the plugin chapters of the main Laravilt docs: the laravilt:plugin and laravilt:make generators, generated plugin structure, plugin classes and registration of resources/pages/widgets, Vue and React frontends, and links to every plugin document.')]
#[IsReadOnly]
#[IsIdempotent]
class PluginDevelopmentGuide extends Tool
{
    protected const SECTION_LIMIT = 5000;

    public function handle(Request $request): Response
    {
        $plugins = Package::query()->where('name', 'laravilt/plugins')->first();
        $meta = Catalog::metaPackage();

        if ($plugins === null && $meta === null) {
            return Catalog::emptyIndexResponse();
        }

        $readme = $plugins?->documents()->where('path', 'README.md')->first();

        $parts = [
            '# Building Laravilt plugins',
            '_Assembled from '
                .($plugins ? "`laravilt/plugins` {$plugins->latest_version} (commit `".substr((string) $plugins->sha, 0, 7).'`)' : 'the Laravilt docs')
                .($meta ? " and `laravilt/laravilt` {$meta->latest_version}" : '')
                .' · '.Str::of(Catalog::lastSyncLine())->trim('_')->after(' · ')->toString().'_',
        ];

        // 1. Overview
        $overview = ['## 1. Overview'];

        if ($readme !== null) {
            $overview[] = Markdown::summary($readme->content, 700);
        }

        // The docs moved folder introductions to README.md; older releases still have introduction.md.
        if ($intro = $this->document($meta, 'docs/plugins/README.md') ?? $this->document($meta, 'docs/plugins/introduction.md')) {
            $overview[] = $this->limit(Markdown::demoteHeadings(Markdown::body($intro->content), 1), 2500);
            $overview[] = $this->source($intro);
        }

        if ($plugins !== null) {
            $overview[] = "Install the plugin system in a Laravilt app (already included by `laravilt/laravilt`):\n\n```bash\n{$plugins->installCommand()}\n```";
        }

        $parts[] = implode("\n\n", array_filter($overview));

        // 2. Generators
        $parts[] = $this->chapter('## 2. Generate a plugin and its components', [
            [$readme, '/^usage$/i'],
            [$this->document($plugins, 'docs/plugin-generation.md'), null],
            [$this->document($plugins, 'docs/component-generators.md'), null],
            [$this->document($meta, 'docs/plugins/getting-started/installation.md'), null],
        ], fallback: "```bash\nphp artisan laravilt:plugin BlogExtensions\nphp artisan laravilt:make blog-extensions model Post\n```");

        // 3. Structure
        $parts[] = $this->chapter('## 3. Plugin structure', [
            [$this->document($meta, 'docs/plugins/getting-started/structure.md'), null],
            [$readme, '/generated plugin structure/i'],
        ]);

        // 4. Plugin classes and registration
        $parts[] = $this->chapter('## 4. Plugin classes and registration', [
            [$this->document($meta, 'docs/plugins/concepts/plugin-classes.md'), null],
            [$this->document($meta, 'docs/plugins/manager/registration.md'), null],
        ], all: true);

        // 5. Components
        $parts[] = $this->chapter('## 5. Registering components (resources, pages, widgets)', [
            [$this->document($meta, 'docs/plugins/components/resources.md'), null],
            [$this->document($meta, 'docs/plugins/components/README.md') ?? $this->document($meta, 'docs/plugins/components/introduction.md'), null],
        ], all: true);

        // 6. Frontend
        $parts[] = $this->frontend($plugins, $meta);

        // 7. Architecture
        $parts[] = $this->chapter('## 7. Generator architecture and custom features', [
            [$this->document($plugins, 'docs/architecture.md'), null],
            [$readme, '/^architecture$/i'],
        ]);

        // 8. Tutorial
        $parts[] = $this->chapter('## 8. Tutorial: creating a plugin', [
            [$this->document($meta, 'docs/plugins/tutorials/creating-a-plugin.md'), null],
            [$this->document($meta, 'docs/plugins/examples/users-plugin.md'), null],
        ]);

        $parts[] = $this->furtherReading($plugins, $meta);

        return Response::text(implode("\n\n", array_filter($parts)));
    }

    protected function document(?Package $package, string $path): ?Document
    {
        return $package?->documents()->where('path', $path)->first()?->setRelation('package', $package);
    }

    /**
     * @param  list<array{0: ?Document, 1: ?string}>  $candidates  [document, optional heading pattern]
     */
    protected function chapter(string $heading, array $candidates, bool $all = false, ?string $fallback = null): ?string
    {
        $blocks = [];

        foreach ($candidates as [$document, $pattern]) {
            if ($document === null) {
                continue;
            }

            $content = $pattern !== null
                ? Markdown::section($document->content, $pattern)
                : Markdown::body($document->content);

            if ($content === null || trim($content) === '') {
                continue;
            }

            if ($pattern !== null) {
                // Drop the matched heading itself; the chapter heading replaces it.
                $content = (string) preg_replace('/\A#{1,6}[^\n]*\n/', '', trim($content));
            }

            $blocks[] = $this->limit($this->normalizeHeadings($content), self::SECTION_LIMIT, $document)
                ."\n\n".$this->source($document);

            if (! $all) {
                break;
            }
        }

        if ($blocks === [] && $fallback === null) {
            return null;
        }

        return $heading."\n\n".($blocks === [] ? $fallback : implode("\n\n", $blocks));
    }

    protected function frontend(?Package $plugins, ?Package $meta): string
    {
        $packages = Package::query()->ordered()->get();
        $vue = $packages->where('has_vue', true)->pluck('repo');
        $react = $packages->where('has_react', true)->pluck('repo');

        $lines = [
            '## 6. Frontend: Vue and React',
            'Laravilt renders its UI with Inertia. Every package that ships UI keeps **Vue** components in `resources/js` and **React** components in `resources/react`, and an application picks one of them with `php artisan laravilt:install --stack=vue` or `--stack=react`. A plugin that adds pages, fields, columns or widgets should therefore ship the same component for both stacks, mirroring the directory layout of the core packages:',
            "```text\nresources/\n├── js/        # Vue 3 single-file components (stack: vue)\n└── react/     # React components (stack: react)\n```",
            '- Packages that currently ship Vue sources: '.($vue->isEmpty() ? '_none_' : $vue->map(fn ($r) => "`{$r}`")->implode(', ')),
            '- Packages that currently ship React sources: '.($react->isEmpty() ? '_none yet_' : $react->map(fn ($r) => "`{$r}`")->implode(', ')),
            'Use a core package with both directories (see `get-package`) as the reference implementation, and keep component names and props identical across stacks so the PHP side stays stack-agnostic.',
        ];

        // Pull any frontend-related sections from the plugin docs.
        $documents = collect([$plugins, $meta])
            ->filter()
            ->flatMap(fn (Package $package) => $package->documents()
                ->where(fn ($query) => $query->where('path', 'README.md')->orWhere('path', 'like', 'docs/%'))
                ->when($package->name === Catalog::META_PACKAGE, fn ($query) => $query->where(fn ($q) => $q
                    ->where('path', 'like', 'docs/plugins/%')
                    ->orWhere('path', 'like', 'docs/%/custom/vue-components.md')
                    ->orWhere('path', 'like', 'docs/%/custom/react-components.md')))
                ->get()
                ->each(fn (Document $document) => $document->setRelation('package', $package)));

        $embedded = 0;

        foreach ($documents as $document) {
            $section = Markdown::section($document->content, '/\b(vue|react|frontend|javascript|inertia)\b/i');

            if ($section === null && preg_match('#/(vue|react)-components\.md$#', $document->path)) {
                $section = Markdown::body($document->content);
            }

            if ($section === null) {
                continue;
            }

            $lines[] = '### From '.$document->package->name.'/'.$document->path;
            $lines[] = $this->limit($this->normalizeHeadings($section, 4), 2500, $document)."\n\n".$this->source($document);

            if (++$embedded >= 3) {
                break;
            }
        }

        return implode("\n\n", $lines);
    }

    protected function furtherReading(?Package $plugins, ?Package $meta): string
    {
        /** @var Collection<int, string> $items */
        $items = collect();

        foreach ([$plugins, $meta] as $package) {
            if ($package === null) {
                continue;
            }

            $package->documents()
                ->where(fn ($query) => $package->name === Catalog::META_PACKAGE
                    ? $query->where('path', 'like', 'docs/plugins/%')
                    : $query->where('path', 'like', 'docs/%')->orWhere('path', 'README.md')->orWhere('path', 'CHANGELOG.md'))
                ->orderBy('path')
                ->get(['path', 'title'])
                ->each(fn (Document $document) => $items->push('- '.$document->title.' — '.Catalog::readDocHint($package, $document->path)));
        }

        return "## Further reading\n\n"
            .($items->isEmpty() ? '_No plugin documents synced._' : $items->implode("\n"))
            ."\n\nSearch across everything with `search-docs {\"query\":\"plugin …\"}`.";
    }

    /**
     * Make embedded headings start at level 3 so they nest under chapters.
     */
    protected function normalizeHeadings(string $content, int $topLevel = 3): string
    {
        preg_match_all('/^(#{1,6})\s/m', (string) preg_replace('/^\s*(```|~~~).*?^\s*\1\s*$/ms', '', $content), $matches);

        $min = collect($matches[1])->map(fn ($hashes) => strlen($hashes))->min();

        return $min === null ? $content : Markdown::demoteHeadings($content, max(0, $topLevel - $min));
    }

    protected function limit(string $content, int $limit, ?Document $document = null): string
    {
        if (mb_strlen($content) <= $limit) {
            return trim($content);
        }

        $cut = mb_substr($content, 0, $limit);

        // Avoid leaving a code fence open.
        if (substr_count($cut, '```') % 2 === 1) {
            $cut .= "\n```";
        }

        return rtrim($cut)."\n\n_… truncated"
            .($document ? '; read the rest with '.Catalog::readDocHint($document->package, $document->path) : '')
            .'._';
    }

    protected function source(Document $document): string
    {
        return '_Source: ['.$document->package->name.'/'.$document->path.']('.$document->source_url.')_';
    }
}

<?php

namespace App\Mcp\Support;

use App\Models\Document;
use App\Models\Package;
use App\Models\Release;
use App\Models\SyncRun;
use App\Support\Markdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;

/**
 * Shared queries and Markdown formatting for the MCP tools, resources and prompts.
 */
class Catalog
{
    public const META_PACKAGE = 'laravilt/laravilt';

    /**
     * @return Collection<int, Package>
     */
    public static function packages(): Collection
    {
        return Package::query()->ordered()->withCount('documents')->get();
    }

    public static function emptyIndexResponse(): Response
    {
        return Response::error('The Laravilt documentation index is empty: the server has not completed its first sync yet. Please try again in a few minutes.');
    }

    public static function unknownPackageResponse(?string $given): Response
    {
        $available = Package::query()->ordered()->pluck('repo')->implode(', ');

        return Response::error(
            $given === null || trim($given) === ''
                ? "A package is required. Available packages: {$available}."
                : "Unknown package [{$given}]. Available packages: {$available}."
        );
    }

    public static function date(?Carbon $date, bool $withTime = false): string
    {
        if ($date === null) {
            return 'unknown';
        }

        return $withTime ? $date->copy()->utc()->format('Y-m-d H:i').' UTC' : $date->format('Y-m-d');
    }

    public static function lastSyncLine(): string
    {
        $run = SyncRun::lastFinished();

        return $run === null
            ? '_Not synced yet._'
            : '_Synced from github.com/'.config('laravilt.github.org').' and Packagist · last sync '.static::date($run->finished_at, true).'._';
    }

    public static function version(Package $package): string
    {
        return $package->latest_version ? $package->latest_version : 'unreleased';
    }

    /**
     * Normalize a document path argument to the stored path.
     */
    public static function findDocument(Package $package, string $path): ?Document
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = (string) preg_replace('#^(\./|/)+#', '', $path);
        $path = (string) preg_replace('#^laravilt://docs/[^/]+/#', '', $path);

        if ($path === '') {
            $path = 'README.md';
        }

        $candidates = array_unique([$path, 'docs/'.$path, $path.'.md', 'docs/'.$path.'.md']);

        $document = $package->documents()->whereIn('path', $candidates)->get()
            ->sortBy(fn (Document $document) => array_search($document->path, $candidates, true))
            ->first();

        if ($document !== null) {
            return $document;
        }

        $suffix = $package->documents()->where('path', 'like', '%/'.addcslashes(ltrim($path, '/'), '%_\\'))->get();

        return $suffix->count() === 1 ? $suffix->first() : null;
    }

    /**
     * @return Collection<int, string>
     */
    public static function similarPaths(Package $package, string $path, int $limit = 8): Collection
    {
        $needle = Str::lower(pathinfo($path, PATHINFO_FILENAME));

        $paths = $package->documents()->pluck('path');

        $matches = $paths->filter(fn (string $candidate): bool => $needle !== '' && str_contains(Str::lower($candidate), $needle));

        return ($matches->isNotEmpty() ? $matches : $paths)->take($limit)->values();
    }

    /**
     * @return array{php: ?string, laravel: ?string, laravilt: array<string, string>, other: array<string, string>}
     */
    public static function requirements(Package $package): array
    {
        $require = $package->requirements();
        $laravel = $require['laravel/framework'] ?? $require['illuminate/support'] ?? $require['illuminate/contracts'] ?? null;

        $laravilt = [];
        $other = [];

        foreach ($require as $name => $constraint) {
            if ($name === 'php' || str_starts_with($name, 'ext-') || $name === 'laravel/framework' || str_starts_with($name, 'illuminate/')) {
                continue;
            }

            if (str_starts_with($name, 'laravilt/')) {
                $laravilt[$name] = (string) $constraint;
            } else {
                $other[$name] = (string) $constraint;
            }
        }

        return [
            'php' => isset($require['php']) ? (string) $require['php'] : null,
            'laravel' => $laravel !== null ? (string) $laravel : null,
            'laravilt' => $laravilt,
            'other' => $other,
        ];
    }

    public static function frontendNotes(Package $package): string
    {
        $lines = [
            '- **Vue:** '.($package->has_vue ? 'yes (`resources/js`)' : 'no Vue sources in this package'),
            '- **React:** '.($package->has_react ? 'yes (`resources/react`)' : 'no React sources yet'),
        ];

        if (! $package->has_vue && ! $package->has_react) {
            $lines[] = '- This is a PHP-only package; its UI (if any) is rendered by the frontend packages.';
        }

        $npm = collect((array) ($package->package_json['peerDependencies'] ?? []))
            ->merge((array) ($package->package_json['dependencies'] ?? []))
            ->keys()
            ->take(12);

        if ($npm->isNotEmpty()) {
            $lines[] = '- **npm dependencies:** '.$npm->map(fn (string $name) => "`{$name}`")->implode(', ');
        }

        return implode("\n", $lines);
    }

    /**
     * Recent changelog entries and releases.
     */
    public static function changelog(Package $package, int $limit): string
    {
        $sections = [];

        $releases = $package->releases()->get()
            ->sort(fn (Release $a, Release $b): int => version_compare($b->normalized ?? $b->version, $a->normalized ?? $a->version))
            ->take($limit);

        if ($releases->isNotEmpty()) {
            $sections[] = "### Releases\n\n".$releases->map(function (Release $release): string {
                $line = '- **'.$release->version.'**'
                    .($release->published_at ? ' — '.static::date($release->published_at) : '')
                    .($release->name && ltrim($release->name, 'vV') !== $release->version ? ' — '.$release->name : '')
                    .($release->url ? " ([link]({$release->url}))" : '');

                $body = trim((string) $release->body);

                if ($body !== '') {
                    $line .= "\n".Str::of(Str::limit($body, 800))->explode("\n")->map(fn ($l) => '  '.rtrim($l))->implode("\n");
                }

                return $line;
            })->implode("\n");
        }

        $changelog = $package->documents()->where('path', 'CHANGELOG.md')->first();
        $entries = $changelog ? array_slice(Markdown::changelogEntries($changelog->content), 0, $limit) : [];

        if ($entries !== []) {
            $sections[] = "### CHANGELOG.md\n\n".collect($entries)
                ->map(fn (array $entry): string => '#### '.$entry['title']."\n\n".Str::limit($entry['body'], 1500))
                ->implode("\n\n")
                ."\n\n[Full changelog]({$changelog->source_url})";
        }

        return $sections === [] ? '_No releases or changelog entries yet._' : implode("\n\n", $sections);
    }

    /**
     * @param  Collection<int, string>  $paths
     */
    public static function tree(Collection $paths, array $titles = []): string
    {
        $tree = [];

        foreach ($paths->sort()->values() as $path) {
            $node = &$tree;

            foreach (explode('/', $path) as $segment) {
                $node[$segment] ??= [];
                $node = &$node[$segment];
            }

            unset($node);
        }

        $lines = [];

        $walk = function (array $nodes, string $prefix, int $depth) use (&$walk, &$lines, $titles): void {
            // Files first, then directories.
            uksort($nodes, fn ($a, $b) => [$nodes[$a] !== [], $a] <=> [$nodes[$b] !== [], $b]);

            foreach ($nodes as $name => $children) {
                $path = ltrim($prefix.'/'.$name, '/');
                $indent = str_repeat('  ', $depth);

                if ($children === []) {
                    $title = $titles[$path] ?? null;
                    $lines[] = $indent.'- `'.$name.'`'.($title ? ' — '.$title : '');
                } else {
                    $lines[] = $indent.'- **'.$name.'/**';
                    $walk($children, $path, $depth + 1);
                }
            }
        };

        $walk($tree, '', 0);

        return implode("\n", $lines);
    }

    public static function readDocHint(Package $package, string $path, ?int $page = null): string
    {
        $arguments = ['package' => $package->repo, 'path' => $path];

        if ($page !== null) {
            $arguments['page'] = $page;
        }

        return '`read-doc '.json_encode($arguments, JSON_UNESCAPED_SLASHES).'`';
    }

    public static function metaPackage(): ?Package
    {
        return Package::query()->where('name', self::META_PACKAGE)->first();
    }

    /**
     * The first existing document from a list of candidates.
     *
     * @param  list<array{0: string, 1: string}>  $candidates  [package name, path]
     */
    public static function firstDocument(array $candidates): ?Document
    {
        foreach ($candidates as [$packageName, $path]) {
            $document = Document::query()
                ->with('package')
                ->whereHas('package', fn ($query) => $query->where('name', $packageName))
                ->where('path', $path)
                ->first();

            if ($document !== null) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Embed a document with a heading and a source link.
     */
    public static function embed(Document $document, int $headingLevel = 2, ?int $limit = null, ?string $title = null): string
    {
        $body = Markdown::demoteHeadings(Markdown::body($document->content), max(0, $headingLevel - 1));

        if ($limit !== null && mb_strlen($body) > $limit) {
            $body = rtrim(mb_substr($body, 0, $limit))."\n\n_… truncated. Read the rest with ".static::readDocHint($document->package, $document->path).'._';
        }

        return str_repeat('#', $headingLevel).' '.($title ?? $document->title)."\n\n"
            ."_Source: [{$document->package->name}/{$document->path}]({$document->source_url})_\n\n"
            .$body;
    }
}

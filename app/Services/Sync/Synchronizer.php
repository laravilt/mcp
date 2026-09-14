<?php

namespace App\Services\Sync;

use App\Models\Document;
use App\Models\Package;
use App\Models\Release;
use App\Models\Repository;
use App\Models\SyncRun;
use App\Services\GitHub\GitHubClient;
use App\Services\Packagist\PackagistClient;
use App\Services\Search\DocumentSearch;
use App\Support\Markdown;
use App\Support\TarballReader;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class Synchronizer
{
    public const UPDATED = 'updated';

    public const SKIPPED = 'skipped';

    public const IGNORED = 'ignored';

    public const REMOVED = 'removed';

    /**
     * Root files that are always extracted, besides Markdown under docs/.
     */
    public const ROOT_FILES = ['README.md', 'CHANGELOG.md', 'composer.json', 'package.json'];

    protected ?Closure $output = null;

    public function __construct(
        protected GitHubClient $github,
        protected PackagistClient $packagist,
        protected TarballReader $reader,
        protected DocumentSearch $search,
    ) {
        //
    }

    /**
     * @param  Closure(string): void|null  $output
     */
    public function withOutput(?Closure $output): static
    {
        $this->output = $output;

        return $this;
    }

    public function org(): string
    {
        return (string) config('laravilt.github.org', 'laravilt');
    }

    /**
     * Sync every Laravilt package in the organization.
     */
    public function syncAll(bool $force = false, string $trigger = 'manual'): SyncRun
    {
        $run = $this->startRun($trigger, 'all');
        $stats = ['repositories' => 0, 'updated' => 0, 'skipped' => 0];
        $errors = [];

        try {
            $repositories = $this->github->organizationRepositories($this->org());
        } catch (Throwable $e) {
            report($e);

            return $this->finishRun($run, $stats, ['organization' => $this->errorMessage($e)], 'failed');
        }

        $seen = [];

        foreach ($repositories as $data) {
            $name = (string) ($data['name'] ?? '');

            if (! $this->eligible($data)) {
                continue;
            }

            $seen[] = $name;
            $stats['repositories']++;

            try {
                $result = $this->syncRepositoryData($data, $force);
                $this->line(sprintf('%-16s %s', $name, $result));

                if ($result === self::UPDATED) {
                    $stats['updated']++;
                } elseif ($result === self::SKIPPED) {
                    $stats['skipped']++;
                }
            } catch (Throwable $e) {
                report($e);
                $errors[$name] = $this->errorMessage($e);
                $this->line(sprintf('%-16s failed: %s', $name, $errors[$name]));
            }
        }

        // Packages whose repository was removed, made private, archived or excluded.
        Package::query()->whereNotIn('repo', $seen)->get()->each(function (Package $package): void {
            $this->removePackage($package);
            $this->line(sprintf('%-16s %s', $package->repo, self::REMOVED));
        });

        Repository::query()->whereNotIn('name', $seen)->delete();

        return $this->finishRun($run, $stats, $errors, $errors === [] ? 'completed' : 'completed_with_errors');
    }

    /**
     * Sync a single repository, e.g. after a push webhook.
     */
    public function syncRepository(string $name, bool $force = false, string $trigger = 'webhook'): SyncRun
    {
        $run = $this->startRun($trigger, $name);
        $stats = ['repositories' => 1, 'updated' => 0, 'skipped' => 0];

        try {
            try {
                $data = $this->github->repository($this->org(), $name);
            } catch (RequestException $e) {
                if ($e->response->notFound()) {
                    $data = null;
                } else {
                    throw $e;
                }
            }

            if ($data === null || ! $this->eligible($data)) {
                if ($package = Package::query()->where('repo', $name)->first()) {
                    $this->removePackage($package);
                }

                Repository::query()->where('name', $name)->delete();
                $this->line(sprintf('%-16s %s', $name, self::IGNORED));

                return $this->finishRun($run, $stats, [], 'completed');
            }

            $result = $this->syncRepositoryData($data, $force);
            $this->line(sprintf('%-16s %s', $name, $result));

            if ($result === self::UPDATED) {
                $stats['updated']++;
            } elseif ($result === self::SKIPPED) {
                $stats['skipped']++;
            }

            return $this->finishRun($run, $stats, [], 'completed');
        } catch (Throwable $e) {
            report($e);

            return $this->finishRun($run, $stats, [$name => $this->errorMessage($e)], 'failed');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function eligible(array $data): bool
    {
        $name = (string) ($data['name'] ?? '');

        return $name !== ''
            && ! in_array(Str::lower($name), array_map('strtolower', (array) config('laravilt.github.exclude', [])), true)
            && ! ($data['private'] ?? false)
            && ! ($data['archived'] ?? false)
            && ! ($data['fork'] ?? false)
            && ! ($data['disabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $data  GitHub repository payload
     */
    protected function syncRepositoryData(array $data, bool $force): string
    {
        $name = (string) $data['name'];
        $branch = (string) ($data['default_branch'] ?? 'main');
        $pushedAt = isset($data['pushed_at']) ? Carbon::parse($data['pushed_at']) : null;

        $repository = Repository::query()->firstOrNew(['name' => $name]);
        $package = Package::query()->where('repo', $name)->first();

        $pushUnchanged = $repository->exists
            && $pushedAt !== null
            && $repository->pushed_at?->equalTo($pushedAt)
            && $repository->default_branch === $branch
            && (! $repository->is_package || ($package !== null && $package->sha === $repository->sha));

        // Nothing was pushed since the last run: only refresh cheap metadata.
        if (! $force && $pushUnchanged) {
            if ($package !== null) {
                $package->fill($this->metadata($data))->save();
                $this->syncVersions($package, withGitHub: false);
            }

            $repository->forceFill(['checked_at' => now()])->save();

            return $repository->is_package ? self::SKIPPED : self::IGNORED;
        }

        $sha = $this->github->headSha($this->org(), $name, $branch);

        if (! $force
            && $repository->exists
            && $repository->sha === $sha
            && (! $repository->is_package || ($package !== null && $package->sha === $sha))) {
            $repository->forceFill(['pushed_at' => $pushedAt, 'default_branch' => $branch, 'checked_at' => now()])->save();

            if ($package !== null) {
                $package->fill([...$this->metadata($data), 'pushed_at' => $pushedAt])->save();
                $this->syncVersions($package, withGitHub: true);
            }

            return $repository->is_package ? self::SKIPPED : self::IGNORED;
        }

        $composerRaw = $this->github->rawFile($this->org(), $name, $sha, 'composer.json');
        $composer = $composerRaw !== null ? json_decode($composerRaw, true) : null;
        $composerName = is_array($composer) ? Str::lower((string) ($composer['name'] ?? '')) : '';
        $isPackage = str_starts_with($composerName, 'laravilt/') && $composerName !== 'laravilt/mcp';

        $repository->forceFill([
            'default_branch' => $branch,
            'sha' => $sha,
            'pushed_at' => $pushedAt,
            'is_package' => $isPackage,
            'composer_name' => $composerName ?: null,
            'checked_at' => now(),
        ])->save();

        if (! $isPackage) {
            if ($package !== null) {
                $this->removePackage($package);
            }

            return self::IGNORED;
        }

        $package = Package::query()->updateOrCreate(['repo' => $name], [
            ...$this->metadata($data),
            'name' => $composerName,
            'default_branch' => $branch,
            'pushed_at' => $pushedAt,
            'composer' => $composer,
            'description' => $composer['description'] ?? ($data['description'] ?? null),
        ]);

        $this->syncContent($package, $sha);
        $this->syncVersions($package, withGitHub: true);

        return self::UPDATED;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function metadata(array $data): array
    {
        return array_filter([
            'repo_url' => $data['html_url'] ?? "https://github.com/{$this->org()}/{$data['name']}",
            'stars' => (int) ($data['stargazers_count'] ?? 0),
        ], fn ($value) => $value !== null);
    }

    /**
     * Download the tarball of a commit and store its documents.
     */
    public function syncContent(Package $package, string $sha): int
    {
        $tarball = $this->github->downloadTarball($this->org(), $package->repo, $sha);
        $maxSize = (int) config('laravilt.sync.max_file_size', 512 * 1024);

        try {
            $archive = $this->reader->read($tarball, function (string $path, int $size) use ($maxSize): bool {
                if ($size > $maxSize) {
                    return false;
                }

                return in_array($path, self::ROOT_FILES, true)
                    || (str_starts_with($path, 'docs/') && preg_match('/\.(md|mdx|markdown)$/i', $path) === 1);
            });
        } finally {
            @unlink($tarball);
        }

        $files = $archive['files'];
        ksort($files);

        $hasVue = Arr::first($archive['paths'], fn (string $path): bool => str_starts_with($path, 'resources/js/')) !== null;
        $hasReact = Arr::first($archive['paths'], fn (string $path): bool => str_starts_with($path, 'resources/react/')) !== null;

        $composer = isset($files['composer.json']) ? json_decode($files['composer.json'], true) : null;
        $packageJson = isset($files['package.json']) ? json_decode($files['package.json'], true) : null;
        $now = now();

        DB::transaction(function () use ($package, $files, $sha, $now, $composer, $packageJson, $hasVue, $hasReact): void {
            $existing = $package->documents()->get()->keyBy('path');

            foreach ($files as $path => $content) {
                $content = mb_scrub((string) $content, 'UTF-8');
                $title = Str::limit(Markdown::title($content, $path), 250);
                $headings = str_ends_with(strtolower($path), '.json') ? [] : Markdown::headings($content);

                /** @var Document $document */
                $document = $existing->get($path) ?? new Document(['package_id' => $package->id, 'path' => $path]);
                $changed = ! $document->exists || $document->content !== $content || $document->title !== $title;

                $document->fill([
                    'title' => $title,
                    'headings' => $headings,
                    'content' => $content,
                    'source_url' => $package->documentUrl($path),
                    'sha' => $sha,
                    'synced_at' => $now,
                ])->save();

                if ($changed) {
                    $this->search->index($document);
                }
            }

            $removed = $existing->keys()->diff(array_keys($files));

            if ($removed->isNotEmpty()) {
                // Eloquent collections implement only() by primary key, so filter by path.
                $ids = $existing->filter(fn (Document $document) => $removed->contains($document->path))->pluck('id')->values();
                $this->search->remove($ids);
                Document::query()->whereIn('id', $ids)->delete();
            }

            $package->fill([
                'sha' => $sha,
                'composer' => is_array($composer) ? $composer : $package->composer,
                'package_json' => is_array($packageJson) ? $packageJson : null,
                'has_vue' => $hasVue,
                'has_react' => $hasReact,
                'content_synced_at' => $now,
            ]);

            if (is_array($composer) && ! empty($composer['description'])) {
                $package->description = $composer['description'];
            }

            $package->save();
        });

        return count($files);
    }

    /**
     * Refresh versions from Packagist and, optionally, GitHub releases/tags.
     */
    public function syncVersions(Package $package, bool $withGitHub = true): void
    {
        $packagistVersions = null;

        try {
            $packagistVersions = $this->packagist->versions($package->name);
        } catch (Throwable $e) {
            report($e);
        }

        foreach (array_slice($packagistVersions ?? [], 0, 200) as $version) {
            Release::query()->updateOrCreate(
                ['package_id' => $package->id, 'version' => $version['version']],
                [
                    'normalized' => $version['normalized'],
                    'published_at' => $version['time'] ? Carbon::parse($version['time']) : null,
                    'url' => $version['url'],
                    'on_packagist' => true,
                ],
            );
        }

        if ($withGitHub) {
            $this->syncGitHubReleases($package, hasPackagistVersions: ! empty($packagistVersions));
        }

        $latest = $packagistVersions ? PackagistClient::latest($packagistVersions) : null;

        $release = $latest !== null
            ? $package->releases()->where('version', $latest['version'])->first()
            : $package->releases()->get()
                ->sort(fn (Release $a, Release $b): int => version_compare($b->normalized ?? $b->version, $a->normalized ?? $a->version))
                ->first();

        $package->forceFill([
            'latest_version' => $release?->version ?? $package->latest_version,
            'latest_release_at' => $release?->published_at ?? $package->latest_release_at,
            'versions_synced_at' => now(),
        ])->save();
    }

    protected function syncGitHubReleases(Package $package, bool $hasPackagistVersions): void
    {
        try {
            $releases = $this->github->releases($this->org(), $package->repo);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        foreach ($releases as $release) {
            if (($release['draft'] ?? false) || empty($release['tag_name'])) {
                continue;
            }

            $version = ltrim((string) $release['tag_name'], 'vV');
            $existing = Release::query()->where('package_id', $package->id)->where('version', $version)->first();

            Release::query()->updateOrCreate(
                ['package_id' => $package->id, 'version' => $version],
                [
                    'tag' => $release['tag_name'],
                    'name' => $release['name'] ?? null,
                    'body' => $release['body'] ?? null,
                    'url' => $release['html_url'] ?? null,
                    'published_at' => $existing?->published_at
                        ?? (isset($release['published_at']) ? Carbon::parse($release['published_at']) : null),
                    'on_github' => true,
                ],
            );
        }

        if ($releases !== [] || $hasPackagistVersions) {
            return;
        }

        try {
            $tags = $this->github->tags($this->org(), $package->repo);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        foreach ($tags as $tag) {
            if (empty($tag['name']) || ! preg_match('/^v?\d+(\.\d+)*/i', (string) $tag['name'])) {
                continue;
            }

            Release::query()->updateOrCreate(
                ['package_id' => $package->id, 'version' => ltrim((string) $tag['name'], 'vV')],
                [
                    'tag' => $tag['name'],
                    'url' => $package->repo_url.'/tree/'.$tag['name'],
                    'on_github' => true,
                ],
            );
        }
    }

    protected function removePackage(Package $package): void
    {
        DB::transaction(function () use ($package): void {
            $this->search->remove($package->documents()->pluck('id'));
            $package->delete();
        });
    }

    protected function startRun(string $trigger, string $scope): SyncRun
    {
        return SyncRun::query()->create([
            'trigger' => $trigger,
            'scope' => $scope,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array{repositories: int, updated: int, skipped: int}  $stats
     * @param  array<string, string>  $errors
     */
    protected function finishRun(SyncRun $run, array $stats, array $errors, string $status): SyncRun
    {
        $run->forceFill([
            ...$stats,
            'status' => $status,
            'packages' => Package::query()->count(),
            'documents' => Document::query()->count(),
            'errors' => $errors === [] ? null : $errors,
            'finished_at' => now(),
        ])->save();

        return $run;
    }

    protected function errorMessage(Throwable $e): string
    {
        return Str::limit(class_basename($e).': '.$e->getMessage(), 300);
    }

    protected function line(string $message): void
    {
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }
}

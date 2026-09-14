<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An in-memory GitHub organization and Packagist backed by Http::fake().
 * Mutate the repositories between syncs to simulate pushes.
 */
class FakeLaravilt
{
    /**
     * @var array<string, array{sha: string, pushed_at: string, branch: string, description: string, private: bool, archived: bool, files: array<string, string>, releases: list<array<string, mixed>>, tags: list<array<string, mixed>>}>
     */
    public array $repositories = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    public array $packagist = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, mixed>  $options
     */
    public function repository(string $name, array $files, array $options = []): self
    {
        $this->repositories[$name] = [
            'sha' => $options['sha'] ?? sha1($name.'-1'),
            'pushed_at' => $options['pushed_at'] ?? '2026-09-01T10:00:00Z',
            'branch' => $options['branch'] ?? 'main',
            'description' => $options['description'] ?? "The {$name} repository",
            'private' => $options['private'] ?? false,
            'archived' => $options['archived'] ?? false,
            'files' => $files,
            'releases' => $options['releases'] ?? [],
            'tags' => $options['tags'] ?? [],
        ];

        return $this;
    }

    /**
     * A typical Laravilt package repository.
     *
     * @param  array<string, string>  $extraFiles
     * @param  array<string, mixed>  $options
     */
    public function package(string $name, array $extraFiles = [], array $options = []): self
    {
        $composer = [
            'name' => 'laravilt/'.$name,
            'description' => $options['composer_description'] ?? 'Laravilt '.ucfirst($name).' package',
            'require' => $options['require'] ?? [
                'php' => '^8.3|^8.4',
                'illuminate/support' => '^12.0|^13.0',
                'laravilt/support' => '^1.0',
                'spatie/laravel-package-tools' => '^1.14',
            ],
        ];

        $files = [
            'composer.json' => json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'README.md' => '# Laravilt '.ucfirst($name)."\n\n[![Tests](https://img.shields.io/badge)](x)\n\nThe {$name} package gives Laravilt apps a powerful {$name} layer with first class Laravel integration.\n\n## Installation\n\n```bash\ncomposer require laravilt/{$name}\n```\n",
            'CHANGELOG.md' => "# Changelog\n\n## [1.1.0] - 2026-08-01\n\n### Added\n- Shiny {$name} feature\n\n### Changed\n\n## [1.0.0] - 2026-01-01\n\n### Added\n- Initial release\n",
            'src/ServiceProvider.php' => '<?php // not synced',
            ...$extraFiles,
        ];

        return $this->repository($name, $files, $options);
    }

    /**
     * @param  list<array{version: string, time?: string}>  $versions  newest first
     */
    public function versions(string $package, array $versions): self
    {
        $this->packagist['laravilt/'.$package] = $versions;

        return $this;
    }

    /**
     * Simulate a push that changes files.
     *
     * @param  array<string, string|null>  $changes  path => contents (null deletes the file)
     */
    public function push(string $name, array $changes, ?string $sha = null, ?string $pushedAt = null): self
    {
        foreach ($changes as $path => $contents) {
            if ($contents === null) {
                unset($this->repositories[$name]['files'][$path]);
            } else {
                $this->repositories[$name]['files'][$path] = $contents;
            }
        }

        $this->repositories[$name]['sha'] = $sha ?? sha1($name.'-'.microtime());
        $this->repositories[$name]['pushed_at'] = $pushedAt ?? '2026-09-10T12:00:00Z';

        return $this;
    }

    public function fake(): self
    {
        Http::preventStrayRequests();

        Http::fake(fn (Request $request): PromiseInterface => $this->respond($request));

        return $this;
    }

    protected function respond(Request $request): PromiseInterface
    {
        $url = urldecode($request->url());
        $path = (string) parse_url($url, PHP_URL_PATH);
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === 'repo.packagist.org' && preg_match('#^/p2/(laravilt/[^/]+)\.json$#', $path, $m)) {
            if (! isset($this->packagist[$m[1]])) {
                return Http::response(['status' => 'error'], 404);
            }

            $entries = array_map(fn (array $version) => [
                'name' => $m[1],
                'version' => $version['version'],
                'version_normalized' => ltrim($version['version'], 'v').'.0',
                'time' => $version['time'] ?? '2026-08-01T00:00:00+00:00',
                'support' => ['source' => "https://github.com/{$m[1]}/tree/{$version['version']}"],
            ], $this->packagist[$m[1]]);

            // Minified format: later entries only carry changed keys.
            foreach ($entries as $index => $entry) {
                if ($index > 0) {
                    unset($entries[$index]['name'], $entries[$index]['support']);
                }
            }

            return Http::response(['minified' => 'composer/2.0', 'packages' => [$m[1] => array_values($entries)]]);
        }

        if ($host === 'raw.githubusercontent.com' && preg_match('#^/laravilt/([^/]+)/([^/]+)/(.+)$#', $path, $m)) {
            $repository = $this->repositories[$m[1]] ?? null;

            if ($repository === null || $repository['sha'] !== $m[2] || ! isset($repository['files'][$m[3]])) {
                return Http::response('404: Not Found', 404);
            }

            return Http::response($repository['files'][$m[3]]);
        }

        if ($host !== 'api.github.com') {
            return Http::response('Unexpected host', 500);
        }

        if ($path === '/orgs/laravilt/repos') {
            return Http::response(array_values(array_map(
                fn (string $name) => $this->payload($name),
                array_keys($this->repositories),
            )));
        }

        if (! preg_match('#^/repos/laravilt/([^/]+)(/.*)?$#', $path, $m) || ! isset($this->repositories[$m[1]])) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        $name = $m[1];
        $repository = $this->repositories[$name];
        $rest = $m[2] ?? '';

        return match (true) {
            $rest === '' => Http::response($this->payload($name)),
            str_starts_with($rest, '/commits/') => Http::response($repository['sha']),
            str_starts_with($rest, '/tarball/') => Http::response(
                Tarball::make($repository['files'], "laravilt-{$name}-".substr($repository['sha'], 0, 7), $repository['sha']),
                200,
                ['Content-Type' => 'application/x-gzip'],
            ),
            $rest === '/releases' => Http::response($repository['releases']),
            $rest === '/tags' => Http::response($repository['tags']),
            default => Http::response(['message' => 'Not Found'], 404),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(string $name): array
    {
        $repository = $this->repositories[$name];

        return [
            'name' => $name,
            'full_name' => "laravilt/{$name}",
            'html_url' => "https://github.com/laravilt/{$name}",
            'description' => $repository['description'],
            'default_branch' => $repository['branch'],
            'pushed_at' => $repository['pushed_at'],
            'private' => $repository['private'],
            'archived' => $repository['archived'],
            'fork' => false,
            'stargazers_count' => 7,
        ];
    }

    /**
     * A small but realistic organization used by most tests.
     */
    public static function organization(): self
    {
        return self::make()
            ->package('laravilt', [
                'docs/getting-started/installation.md' => "---\ntitle: Installation\n---\n\n# Installation\n\nInstall Laravilt in your Laravel project.\n\n## Step 1: Create Laravel Project\n\n```bash\nlaravel new my-project\n```\n\n## Step 2: Install Laravilt\n\n```bash\nphp artisan laravilt:install\n```\n",
                'docs/getting-started/first-resource.md' => "# Your First Resource\n\nRun `php artisan laravilt:resource admin --model=Product` to generate a resource with a form and a table.\n",
                'docs/panel/resources/introduction.md' => "# Resources\n\nResources describe how a model is managed in a panel.\n\n## Forms\n\nDefine the form schema.\n\n## Tables\n\nDefine table columns and filters.\n",
                'docs/plugins/introduction.md' => "# Plugins\n\nPlugins extend Laravilt panels with reusable features.\n",
                'docs/plugins/getting-started/structure.md' => "# Plugin Structure\n\n## Directory Tree\n\n```text\nmy-plugin/\n  src/\n  resources/js/\n```\n",
                'docs/plugins/manager/registration.md' => "# Registration\n\n## Auto-Discovery\n\nPlugins are discovered from composer.json.\n",
            ], [
                'require' => ['php' => '^8.3|^8.4', 'laravilt/panel' => '^1.0', 'laravilt/forms' => '^1.0', 'laravilt/plugins' => '^1.0'],
                'composer_description' => 'Laravilt - Modern Laravel Admin Panel',
            ])
            ->package('panel', [
                'docs/index.md' => "# Panel\n\nBuild admin panels with resources, pages and navigation.\n",
                'docs/relation-managers.md' => "# Relation Managers\n\nRelation managers let you manage related records.\n\n## Creating a Relation Manager\n\n```bash\nphp artisan laravilt:relation-manager\n```\n",
                'resources/js/app.ts' => 'export {}',
                'resources/react/app.tsx' => 'export {}',
                'package.json' => json_encode(['name' => '@laravilt/panel', 'peerDependencies' => ['vue' => '^3.5', 'react' => '^19.0']]),
            ], [
                'releases' => [
                    ['tag_name' => 'v1.1.0', 'name' => 'v1.1.0', 'body' => 'Adds relation manager improvements.', 'html_url' => 'https://github.com/laravilt/panel/releases/tag/v1.1.0', 'published_at' => '2026-08-01T00:00:00Z', 'draft' => false],
                ],
            ])
            ->package('forms', [
                'docs/index.md' => "# Forms\n\nForm builder with text inputs, selects and date pickers. Tables are covered by the tables package.\n",
                'docs/fields/text-input.md' => "# TextInput\n\nThe TextInput field renders a text input with validation.\n",
                'resources/js/fields/TextInput.vue' => '<template />',
            ])
            ->package('plugins', [
                'docs/index.md' => "# Laravilt Plugins Documentation\n\n```bash\nphp artisan laravilt:plugin BlogExtensions\n```\n",
                'docs/plugin-generation.md' => "# Plugin Generation\n\nGenerate a plugin with `php artisan laravilt:plugin BlogExtensions`.\n\n## Options\n\n- `--vendor`\n",
            ])
            ->repository('laravilt.com', [
                'composer.json' => json_encode(['name' => 'laravel/vue-starter-kit']),
                'README.md' => '# Website',
            ])
            ->repository('mcp', [
                'composer.json' => json_encode(['name' => 'laravilt/mcp']),
            ])
            ->repository('skeleton', [
                'composer.json' => json_encode(['name' => 'acme/skeleton']),
                'docs/index.md' => '# Not a Laravilt package',
            ])
            ->versions('laravilt', [['version' => 'v1.0.3', 'time' => '2026-02-23T10:00:00+00:00'], ['version' => 'v1.0.2']])
            ->versions('panel', [['version' => 'v1.1.0', 'time' => '2026-08-01T00:00:00+00:00'], ['version' => 'v1.0.15', 'time' => '2026-02-23T17:25:48+00:00'], ['version' => 'v1.2.0-beta1', 'time' => '2026-09-01T00:00:00+00:00']])
            ->versions('forms', [['version' => 'v1.0.8', 'time' => '2026-02-25T00:00:00+00:00']])
            ->versions('plugins', [['version' => 'v1.0.2', 'time' => '2025-12-21T00:00:00+00:00']]);
    }
}

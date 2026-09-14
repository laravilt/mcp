<?php

use App\Mcp\Prompts\BuildResource;
use App\Mcp\Resources\DocumentResource;
use App\Mcp\Resources\PackagesResource;
use App\Mcp\Servers\LaraviltServer;
use App\Mcp\Tools\GetChangelog;
use App\Mcp\Tools\GetLatestVersions;
use App\Mcp\Tools\GetPackage;
use App\Mcp\Tools\InstallationGuide;
use App\Mcp\Tools\ListDocs;
use App\Mcp\Tools\ListPackages;
use App\Mcp\Tools\PluginDevelopmentGuide;
use App\Mcp\Tools\ReadDoc;
use App\Mcp\Tools\SearchDocs;
use App\Models\Package;

describe('before the first sync', function () {
    it('explains that the index is empty', function (string $tool, array $arguments) {
        LaraviltServer::tool($tool, $arguments)
            ->assertHasErrors(['has not completed its first sync']);
    })->with([
        [ListPackages::class, []],
        [GetPackage::class, ['package' => 'panel']],
        [SearchDocs::class, ['query' => 'panel']],
        [ReadDoc::class, ['package' => 'panel', 'path' => 'README.md']],
        [ListDocs::class, []],
        [GetLatestVersions::class, []],
        [GetChangelog::class, ['package' => 'panel']],
        [PluginDevelopmentGuide::class, []],
        [InstallationGuide::class, []],
    ]);
});

describe('after syncing', function () {
    beforeEach(function () {
        syncLaravilt();
    });

    it('lists packages', function () {
        LaraviltServer::tool(ListPackages::class)
            ->assertOk()
            ->assertName('list-packages')
            ->assertSee([
                '# Laravilt packages (4)',
                '## laravilt/laravilt — 1.0.3 (2026-02-23)',
                '## laravilt/panel — 1.1.0 (2026-08-01)',
                '- Install: `composer require laravilt/panel:^1.1.0`',
                '- Repository: https://github.com/laravilt/panel',
                'Frontend: Vue + React',
            ])
            ->assertDontSee(['laravilt/mcp', 'skeleton']);
    });

    it('gets a package', function () {
        LaraviltServer::tool(GetPackage::class, ['package' => 'laravilt/panel'])
            ->assertOk()
            ->assertSee([
                '# laravilt/panel',
                '**Latest version:** 1.1.0 (released 2026-08-01)',
                'The panel package gives Laravilt apps a powerful panel layer',
                '**PHP:** `^8.3|^8.4`',
                '**Laravel:** `^12.0|^13.0`',
                '**Laravilt packages:** `laravilt/support` ^1.0',
                '`spatie/laravel-package-tools` ^1.14',
                '**Vue:** yes (`resources/js`)',
                '**React:** yes (`resources/react`)',
                '`vue`, `react`',
                '`docs/relation-managers.md` — Relation Managers',
                '**1.1.0** — 2026-08-01',
                'Adds relation manager improvements.',
                '#### [1.1.0] - 2026-08-01',
            ]);
    });

    it('reports unknown packages with the available names', function () {
        LaraviltServer::tool(GetPackage::class, ['package' => 'nope'])
            ->assertHasErrors(['Unknown package [nope]. Available packages: laravilt, forms, panel, plugins.']);
    });

    it('searches docs', function () {
        LaraviltServer::tool(SearchDocs::class, ['query' => 'relation manager', 'limit' => 3])
            ->assertOk()
            ->assertSee([
                '# Search results for "relation manager"',
                '1. **Relation Managers** — `laravilt/panel` · `docs/relation-managers.md`',
                '[GitHub](https://github.com/laravilt/panel/blob/main/docs/relation-managers.md)',
                '`read-doc {"package":"panel","path":"docs/relation-managers.md"}`',
            ]);

        LaraviltServer::tool(SearchDocs::class, ['query' => 'TextInput', 'package' => 'panel'])
            ->assertSee('No documentation matched "TextInput" in laravilt/panel');
    });

    it('validates search arguments', function () {
        LaraviltServer::tool(SearchDocs::class, [])->assertHasErrors(['Provide a "query"']);
        LaraviltServer::tool(SearchDocs::class, ['query' => 'x', 'limit' => 500])->assertHasErrors();
    });

    it('reads a document, resolving short paths', function () {
        LaraviltServer::tool(ReadDoc::class, ['package' => 'panel', 'path' => 'relation-managers'])
            ->assertOk()
            ->assertSee([
                '# Relation Managers',
                'laravilt/panel · `docs/relation-managers.md`',
                'php artisan laravilt:relation-manager',
            ]);

        LaraviltServer::tool(ReadDoc::class, ['package' => 'panel', 'path' => 'composer.json'])
            ->assertSee('```json');
    });

    it('paginates long documents', function () {
        config()->set('laravilt.docs.page_size', 1000);

        $package = Package::findByIdentifier('panel');
        $package->documents()->where('path', 'docs/index.md')->update([
            'content' => "# Panel\n\n".implode("\n", array_map(fn ($i) => "Line {$i} ".str_repeat('x', 40), range(1, 100))),
        ]);

        LaraviltServer::tool(ReadDoc::class, ['package' => 'panel', 'path' => 'docs/index.md'])
            ->assertSee(['page 1 of 5', 'Line 1 ', '`read-doc {"package":"panel","path":"docs/index.md","page":2}`'])
            ->assertDontSee('Line 100 ');

        LaraviltServer::tool(ReadDoc::class, ['package' => 'panel', 'path' => 'docs/index.md', 'page' => 5])
            ->assertSee(['page 5 of 5', 'Line 100 '])
            ->assertDontSee('Continue with');

        LaraviltServer::tool(ReadDoc::class, ['package' => 'panel', 'path' => 'docs/index.md', 'page' => 9])
            ->assertHasErrors(['Page 9 does not exist']);
    });

    it('suggests paths for missing documents', function () {
        LaraviltServer::tool(ReadDoc::class, ['package' => 'panel', 'path' => 'docs/relation.md'])
            ->assertHasErrors(['was not found in laravilt/panel', '`docs/relation-managers.md`']);
    });

    it('lists docs for one package or all packages', function () {
        LaraviltServer::tool(ListDocs::class, ['package' => 'laravilt'])
            ->assertOk()
            ->assertSee([
                '# laravilt/laravilt documentation (9 files)',
                '- **docs/**',
                '  - **getting-started/**',
                '    - `installation.md` — Installation',
            ]);

        LaraviltServer::tool(ListDocs::class)
            ->assertOk()
            ->assertSee(['## laravilt/laravilt (9 files)', '## laravilt/panel (6 files)', '- `relation-managers.md`']);
    });

    it('gets latest versions with structured content', function () {
        LaraviltServer::tool(GetLatestVersions::class)
            ->assertOk()
            ->assertSee([
                '"laravilt/panel": "1.1.0"',
                'composer require laravilt/laravilt:^1.0.3',
                'composer require laravilt/forms:^1.0.8 laravilt/panel:^1.1.0 laravilt/plugins:^1.0.2',
            ])
            ->assertStructuredContent(['versions' => [
                'laravilt/laravilt' => '1.0.3',
                'laravilt/forms' => '1.0.8',
                'laravilt/panel' => '1.1.0',
                'laravilt/plugins' => '1.0.2',
            ]]);
    });

    it('gets the changelog', function () {
        LaraviltServer::tool(GetChangelog::class, ['package' => 'panel', 'limit' => 2])
            ->assertOk()
            ->assertSee([
                '# laravilt/panel changelog',
                '- **1.2.0-beta1** — 2026-09-01',
                '- **1.1.0** — 2026-08-01',
                '#### [1.1.0] - 2026-08-01',
                '#### [1.0.0] - 2026-01-01',
                '- Shiny panel feature',
            ])
            ->assertDontSee('**1.0.15**');
    });

    it('assembles the plugin development guide', function () {
        LaraviltServer::tool(PluginDevelopmentGuide::class)
            ->assertOk()
            ->assertSee([
                '# Building Laravilt plugins',
                '`laravilt/plugins` 1.0.2',
                '## 2. Generate a plugin and its components',
                'php artisan laravilt:plugin BlogExtensions',
                '## 3. Plugin structure',
                '## 4. Plugin classes and registration',
                'Plugins are discovered from composer.json.',
                '## 6. Frontend: Vue and React',
                'Packages that currently ship React sources: `panel`',
                '## Further reading',
                '`read-doc {"package":"plugins","path":"docs/plugin-generation.md"}`',
            ]);
    });

    it('builds the installation guide for each stack', function () {
        LaraviltServer::tool(InstallationGuide::class, ['stack' => 'react'])
            ->assertOk()
            ->assertSee([
                '# Installing Laravilt (React stack)',
                'composer require laravilt/laravilt:^1.0.3',
                'php artisan laravilt:install --stack=react',
                'Packages with React sources: `panel`',
                'Not yet shipping React sources: `forms`',
                '## Official installation guide',
                'laravel new my-project',
            ]);

        LaraviltServer::tool(InstallationGuide::class)
            ->assertSee(['# Installing Laravilt (Vue stack)', 'php artisan laravilt:install --stack=vue']);

        LaraviltServer::tool(InstallationGuide::class, ['stack' => 'svelte'])
            ->assertHasErrors(['The stack must be "vue" or "react".']);
    });

    it('serves the packages resource', function () {
        LaraviltServer::resource(PackagesResource::class)
            ->assertOk()
            ->assertSee(['# Latest Laravilt versions', '| `laravilt/panel` | 1.1.0 | 2026-08-01 | Vue + React | https://github.com/laravilt/panel |']);
    });

    it('serves document resources', function () {
        LaraviltServer::resource(DocumentResource::class, ['package' => 'panel', 'path' => 'README.md'])
            ->assertOk()
            ->assertSee('# Laravilt Panel');

        LaraviltServer::resource(DocumentResource::class, ['package' => 'panel', 'path' => 'missing.md'])
            ->assertHasErrors(['Document [missing.md] was not found']);
    });

    it('serves the build-resource prompt', function () {
        LaraviltServer::prompt(BuildResource::class, ['model' => 'App\\Models\\Invoice', 'stack' => 'react'])
            ->assertOk()
            ->assertSee([
                'Build a Laravilt panel resource for the `Invoice` Eloquent model in the `admin` panel',
                'php artisan laravilt:resource admin --model=Invoice',
                '--stack=react',
                '## Your First Resource',
            ]);

        LaraviltServer::prompt(BuildResource::class, [])->assertHasErrors();
    });
});

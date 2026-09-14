<?php

namespace App\Mcp\Servers;

use App\Mcp\Methods\ListResources;
use App\Mcp\Prompts\BuildResource;
use App\Mcp\Resources\DocumentResource;
use App\Mcp\Resources\PackagesResource;
use App\Mcp\Tools\GetChangelog;
use App\Mcp\Tools\GetLatestVersions;
use App\Mcp\Tools\GetPackage;
use App\Mcp\Tools\InstallationGuide;
use App\Mcp\Tools\ListDocs;
use App\Mcp\Tools\ListPackages;
use App\Mcp\Tools\PluginDevelopmentGuide;
use App\Mcp\Tools\ReadDoc;
use App\Mcp\Tools\SearchDocs;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\Methods\CompletionComplete;
use Laravel\Mcp\Server\Methods\GetPrompt;
use Laravel\Mcp\Server\Methods\ListPrompts;
use Laravel\Mcp\Server\Methods\ListResourceTemplates;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Methods\Ping;
use Laravel\Mcp\Server\Methods\ReadResource;

class LaraviltServer extends Server
{
    public const TOOLS = [
        ListPackages::class,
        GetPackage::class,
        SearchDocs::class,
        ReadDoc::class,
        ListDocs::class,
        GetLatestVersions::class,
        GetChangelog::class,
        PluginDevelopmentGuide::class,
        InstallationGuide::class,
    ];

    public const RESOURCES = [
        PackagesResource::class,
        DocumentResource::class,
    ];

    public const PROMPTS = [
        BuildResource::class,
    ];

    protected string $name = 'Laravilt MCP';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Laravilt MCP gives read-only access to the latest documentation, READMEs, changelogs and released versions of every Laravilt package (laravilt/*), synced from GitHub and Packagist.

        Laravilt is a Laravel admin panel framework (panels, resources, forms, tables, actions, infolists, widgets, notifications, AI, plugins) with Vue and React (Inertia) frontends.

        - Start with `list-packages` or `search-docs`, then open results with `read-doc`.
        - Use `get-latest-versions` before writing composer constraints; never guess versions.
        - Use `installation-guide` for new apps and `plugin-development-guide` for building plugins.
        - The laravilt/laravilt meta package contains the main framework guide under docs/.
        MARKDOWN;

    public int $maxPaginationLength = 100;

    public int $defaultPaginationLength = 50;

    protected array $tools = self::TOOLS;

    protected array $resources = self::RESOURCES;

    protected array $prompts = self::PROMPTS;

    protected array $methods = [
        'tools/list' => ListTools::class,
        'tools/call' => CallTool::class,
        'resources/list' => ListResources::class,
        'resources/read' => ReadResource::class,
        'resources/templates/list' => ListResourceTemplates::class,
        'prompts/list' => ListPrompts::class,
        'prompts/get' => GetPrompt::class,
        'completion/complete' => CompletionComplete::class,
        'ping' => Ping::class,
    ];

    protected function boot(): void
    {
        $this->name = (string) config('laravilt.server.name', $this->name);
        $this->version = (string) config('laravilt.server.version', $this->version);
    }
}

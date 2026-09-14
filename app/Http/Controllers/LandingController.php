<?php

namespace App\Http\Controllers;

use App\Mcp\Servers\LaraviltServer;
use App\Mcp\Support\Catalog;
use App\Models\Document;
use App\Models\SyncRun;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

class LandingController
{
    public function __invoke(): View
    {
        $endpoint = rtrim((string) config('app.url'), '/').'/'.trim((string) config('laravilt.server.path', 'mcp'), '/');

        $tools = Cache::remember('landing:tools', 3600, fn () => collect(LaraviltServer::TOOLS)
            ->map(fn (string $class): Tool => app($class))
            ->map(fn (Tool $tool): array => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'arguments' => collect($tool->toArray()['inputSchema']['properties'] ?? [])
                    ->keys()
                    ->map(fn (string $key) => in_array($key, $tool->toArray()['inputSchema']['required'] ?? [], true) ? $key : $key.'?')
                    ->all(),
            ])
            ->all());

        $prompts = collect(LaraviltServer::PROMPTS)
            ->map(fn (string $class): Prompt => app($class))
            ->map(fn (Prompt $prompt): array => ['name' => $prompt->name(), 'description' => $prompt->description()])
            ->all();

        return view('landing', [
            'endpoint' => $endpoint,
            'tools' => $tools,
            'prompts' => $prompts,
            'packages' => Catalog::packages(),
            'documents' => Document::query()->count(),
            'lastSync' => SyncRun::lastFinished(),
        ]);
    }
}

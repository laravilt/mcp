@php
    $snippets = [
        'claude-code' => [
            'label' => 'Claude Code',
            'hint' => 'Run in your terminal',
            'lang' => 'bash',
            'code' => "claude mcp add --transport http laravilt {$endpoint}",
        ],
        'cursor' => [
            'label' => 'Cursor',
            'hint' => '.cursor/mcp.json',
            'lang' => 'json',
            'code' => json_encode(['mcpServers' => ['laravilt' => ['url' => $endpoint]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ],
        'vscode' => [
            'label' => 'VS Code',
            'hint' => '.vscode/mcp.json',
            'lang' => 'json',
            'code' => json_encode(['servers' => ['laravilt' => ['type' => 'http', 'url' => $endpoint]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ],
        'claude-desktop' => [
            'label' => 'Claude Desktop',
            'hint' => 'claude_desktop_config.json (via mcp-remote)',
            'lang' => 'json',
            'code' => json_encode(['mcpServers' => ['laravilt' => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $endpoint]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ],
        'generic' => [
            'label' => 'Any client',
            'hint' => 'ChatGPT connectors, Windsurf, Zed, custom agents',
            'lang' => 'text',
            'code' => "URL:       {$endpoint}\nTransport: Streamable HTTP (JSON-RPC over POST)\nAuth:      none (public, read-only, rate limited)",
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravilt MCP — Laravilt docs and versions for your AI assistant</title>
    <meta name="description" content="A public remote MCP server that gives Claude Code, Cursor, Claude Desktop and ChatGPT the latest Laravilt documentation, package versions and plugin guides.">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect x="4" y="22.4" width="18.4" height="18.4" fill="#FF2D20"/><rect x="22.4" y="40.8" width="18.4" height="18.4" fill="#FF2D20"/><rect x="4" y="59.2" width="18.4" height="18.4" fill="#FF2D20"/><rect x="40.8" y="22.4" width="18.4" height="18.4" fill="#9553E9"/><rect x="40.8" y="59.2" width="18.4" height="18.4" fill="#9553E9"/><rect x="59.2" y="40.8" width="18.4" height="18.4" fill="#9553E9"/><rect x="77.6" y="22.4" width="18.4" height="18.4" fill="#9553E9"/></svg>') }}">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style type="text/tailwindcss">
        @theme {
            --color-brand: #FF2D20;
            --color-accent: #9553E9;
            --font-sans: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            --font-mono: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
    </style>
    <style>
        .stripes {
            background-image: repeating-linear-gradient(-45deg, var(--stripe) 0 1px, transparent 1px 8px);
        }
        :root { --stripe: rgb(24 24 27 / 0.12); }
        @media (prefers-color-scheme: dark) { :root { --stripe: rgb(255 255 255 / 0.10); } }
    </style>
</head>
<body class="bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100 font-sans">

{{-- Header --}}
<header class="border-b border-zinc-200 dark:border-zinc-800">
    <div class="mx-auto flex max-w-5xl items-center justify-between border-x border-zinc-200 px-6 py-4 dark:border-zinc-800">
        <a href="/" class="flex items-center gap-3">
            <svg class="size-8" viewBox="0 0 100 100" aria-hidden="true">
                <rect x="4" y="22.4" width="18.4" height="18.4" fill="#FF2D20"/><rect x="22.4" y="40.8" width="18.4" height="18.4" fill="#FF2D20"/><rect x="4" y="59.2" width="18.4" height="18.4" fill="#FF2D20"/><rect x="40.8" y="22.4" width="18.4" height="18.4" fill="#9553E9"/><rect x="40.8" y="59.2" width="18.4" height="18.4" fill="#9553E9"/><rect x="59.2" y="40.8" width="18.4" height="18.4" fill="#9553E9"/><rect x="77.6" y="22.4" width="18.4" height="18.4" fill="#9553E9"/>
            </svg>
            <span class="text-lg font-semibold tracking-tight">Laravilt <span class="text-zinc-400">MCP</span></span>
        </a>
        <nav class="flex items-center gap-5 text-sm text-zinc-500 dark:text-zinc-400">
            <a href="#connect" class="hover:text-zinc-900 dark:hover:text-white">Connect</a>
            <a href="#tools" class="hover:text-zinc-900 dark:hover:text-white">Tools</a>
            <a href="#packages" class="hover:text-zinc-900 dark:hover:text-white">Packages</a>
            <a href="https://github.com/laravilt/mcp" class="hover:text-zinc-900 dark:hover:text-white">GitHub</a>
        </nav>
    </div>
</header>

<main>
    {{-- Hero --}}
    <section class="border-b border-zinc-200 dark:border-zinc-800">
        <div class="mx-auto max-w-5xl border-x border-zinc-200 px-6 py-20 sm:py-24 dark:border-zinc-800">
            <p class="inline-flex items-center gap-2 border border-zinc-200 px-3 py-1 font-mono text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                <span class="size-1.5 bg-brand"></span> Model Context Protocol · Streamable HTTP
            </p>
            <h1 class="mt-6 max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                The latest <span class="text-brand">Laravilt</span> docs, versions and plugin guides — <span class="text-accent">inside your AI assistant.</span>
            </h1>
            <p class="mt-6 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">
                A public, read-only MCP server for Claude Code, Cursor, Claude Desktop and ChatGPT. It syncs every
                <code class="font-mono text-base">laravilt/*</code> package from GitHub and Packagist, so assistants stop guessing APIs and versions.
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="flex min-w-0 items-center border border-zinc-900 bg-zinc-950 font-mono text-sm text-zinc-100 dark:border-zinc-700">
                    <span class="select-none px-3 text-zinc-500">$</span>
                    <code class="truncate py-3 pr-3" id="hero-command">claude mcp add --transport http laravilt {{ $endpoint }}</code>
                    <button type="button" data-copy="hero-command" class="border-l border-zinc-700 px-3 py-3 text-xs text-zinc-400 hover:bg-zinc-800 hover:text-white">Copy</button>
                </div>
            </div>
        </div>
    </section>

    {{-- Stats --}}
    <section class="border-b border-zinc-200 dark:border-zinc-800">
        <div class="mx-auto grid max-w-5xl grid-cols-2 border-x border-zinc-200 sm:grid-cols-4 dark:border-zinc-800">
            @foreach ([
                ['Packages', $packages->count()],
                ['Documents', number_format($documents)],
                ['Tools', count($tools)],
                ['Last sync', $lastSync ? $lastSync->finished_at->diffForHumans() : 'pending'],
            ] as $i => [$label, $value])
                <div class="px-6 py-6 {{ $i > 0 ? 'border-l' : '' }} {{ $i === 2 ? 'max-sm:border-l-0 max-sm:border-t' : '' }} {{ $i === 3 ? 'max-sm:border-t' : '' }} border-zinc-200 dark:border-zinc-800">
                    <div class="font-mono text-xs uppercase tracking-wider text-zinc-500">{{ $label }}</div>
                    <div class="mt-2 text-2xl font-semibold tracking-tight" @if ($label === 'Last sync' && $lastSync) title="{{ $lastSync->finished_at->toIso8601String() }}" @endif>{{ $value }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <div class="stripes h-6 border-b border-zinc-200 dark:border-zinc-800"></div>

    {{-- Connect --}}
    <section id="connect" class="border-b border-zinc-200 dark:border-zinc-800">
        <div class="mx-auto max-w-5xl border-x border-zinc-200 dark:border-zinc-800">
            <div class="border-b border-zinc-200 px-6 py-10 dark:border-zinc-800">
                <h2 class="text-2xl font-semibold tracking-tight">Connect your assistant</h2>
                <p class="mt-2 text-zinc-600 dark:text-zinc-400">No API key, no account. Point any MCP client at <code class="font-mono text-sm text-brand">{{ $endpoint }}</code>.</p>
            </div>
            <div class="flex flex-wrap border-b border-zinc-200 dark:border-zinc-800" role="tablist">
                @foreach ($snippets as $key => $snippet)
                    <button type="button" role="tab" data-tab="{{ $key }}"
                            class="tab border-r border-zinc-200 px-5 py-3 text-sm font-medium text-zinc-500 hover:text-zinc-900 aria-selected:bg-zinc-50 aria-selected:text-zinc-900 aria-selected:shadow-[inset_0_-2px_0_#FF2D20] dark:border-zinc-800 dark:hover:text-white dark:aria-selected:bg-zinc-900 dark:aria-selected:text-white"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                        {{ $snippet['label'] }}
                    </button>
                @endforeach
            </div>
            @foreach ($snippets as $key => $snippet)
                <div data-panel="{{ $key }}" class="{{ $loop->first ? '' : 'hidden' }}">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-2 font-mono text-xs text-zinc-500 dark:border-zinc-800">
                        <span>{{ $snippet['hint'] }}</span>
                        <button type="button" data-copy="snippet-{{ $key }}" class="px-2 py-1 hover:text-zinc-900 dark:hover:text-white">Copy</button>
                    </div>
                    <pre class="overflow-x-auto bg-zinc-950 px-6 py-5 font-mono text-sm leading-relaxed text-zinc-100"><code id="snippet-{{ $key }}">{{ $snippet['code'] }}</code></pre>
                </div>
            @endforeach
        </div>
    </section>

    <div class="stripes h-6 border-b border-zinc-200 dark:border-zinc-800"></div>

    {{-- Tools --}}
    <section id="tools" class="border-b border-zinc-200 dark:border-zinc-800">
        <div class="mx-auto max-w-5xl border-x border-zinc-200 dark:border-zinc-800">
            <div class="border-b border-zinc-200 px-6 py-10 dark:border-zinc-800">
                <h2 class="text-2xl font-semibold tracking-tight">What it offers</h2>
                <p class="mt-2 text-zinc-600 dark:text-zinc-400">
                    {{ count($tools) }} tools, resources at <code class="font-mono text-sm">laravilt://packages</code> and
                    <code class="font-mono text-sm">laravilt://docs/{package}/{path}</code>, and a
                    @foreach ($prompts as $prompt)<code class="font-mono text-sm">{{ $prompt['name'] }}</code>@endforeach prompt.
                </p>
            </div>
            <div class="grid sm:grid-cols-2">
                @foreach ($tools as $tool)
                    <div class="border-b border-zinc-200 px-6 py-6 dark:border-zinc-800 {{ $loop->odd ? 'sm:border-r' : '' }}">
                        <div class="flex flex-wrap items-baseline gap-x-2 font-mono text-sm">
                            <span class="font-semibold {{ $loop->index % 2 === 0 ? 'text-brand' : 'text-accent' }}">{{ $tool['name'] }}</span>
                            @if ($tool['arguments'])
                                <span class="text-zinc-400">{{ '{'.implode(', ', $tool['arguments']).'}' }}</span>
                            @endif
                        </div>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $tool['description'] }}</p>
                    </div>
                @endforeach
                @if (count($tools) % 2 === 1)
                    <div class="stripes hidden border-b border-zinc-200 sm:block dark:border-zinc-800"></div>
                @endif
            </div>
        </div>
    </section>

    <div class="stripes h-6 border-b border-zinc-200 dark:border-zinc-800"></div>

    {{-- Packages --}}
    <section id="packages" class="border-b border-zinc-200 dark:border-zinc-800">
        <div class="mx-auto max-w-5xl border-x border-zinc-200 dark:border-zinc-800">
            <div class="flex flex-col gap-2 border-b border-zinc-200 px-6 py-10 sm:flex-row sm:items-end sm:justify-between dark:border-zinc-800">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight">Package versions</h2>
                    <p class="mt-2 text-zinc-600 dark:text-zinc-400">Synced from GitHub and Packagist every {{ config('laravilt.sync.interval') }} minutes and on every push.</p>
                </div>
                <p class="font-mono text-xs text-zinc-500">
                    @if ($lastSync)
                        Last sync {{ $lastSync->finished_at->utc()->format('Y-m-d H:i') }} UTC · {{ $lastSync->status }}
                    @else
                        Waiting for the first sync
                    @endif
                </p>
            </div>
            @if ($packages->isEmpty())
                <p class="px-6 py-10 text-sm text-zinc-500">No packages synced yet. Run <code class="font-mono">php artisan laravilt:sync</code>.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="font-mono text-xs uppercase tracking-wider text-zinc-500">
                        <tr class="border-b border-zinc-200 dark:border-zinc-800">
                            <th class="px-6 py-3 font-medium">Package</th>
                            <th class="px-6 py-3 font-medium">Version</th>
                            <th class="px-6 py-3 font-medium">Released</th>
                            <th class="px-6 py-3 font-medium">Frontend</th>
                            <th class="px-6 py-3 font-medium text-right">Docs</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($packages as $package)
                            <tr class="border-b border-zinc-200 last:border-b-0 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-900">
                                <td class="px-6 py-3">
                                    <a href="{{ $package->repo_url }}" class="font-mono hover:text-brand">{{ $package->name }}</a>
                                </td>
                                <td class="px-6 py-3 font-mono">{{ $package->latest_version ?? '—' }}</td>
                                <td class="px-6 py-3 text-zinc-500">{{ $package->latest_release_at?->format('M j, Y') ?? '—' }}</td>
                                <td class="px-6 py-3 text-zinc-500">{{ $package->frontendSummary() }}</td>
                                <td class="px-6 py-3 text-right font-mono text-zinc-500">{{ $package->documents_count }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    <div class="stripes h-6 border-b border-zinc-200 dark:border-zinc-800"></div>

    {{-- Try it --}}
    <section class="border-b border-zinc-200 dark:border-zinc-800">
        <div class="mx-auto grid max-w-5xl border-x border-zinc-200 md:grid-cols-2 dark:border-zinc-800">
            <div class="border-b border-zinc-200 px-6 py-10 md:border-r md:border-b-0 dark:border-zinc-800">
                <h2 class="text-2xl font-semibold tracking-tight">Ask things like</h2>
                <ul class="mt-4 space-y-3 text-sm text-zinc-600 dark:text-zinc-400">
                    <li class="border-l-2 border-brand pl-3">“Install Laravilt with the React stack on my new Laravel 13 app.”</li>
                    <li class="border-l-2 border-accent pl-3">“Build a Laravilt resource for my Invoice model with filters and bulk actions.”</li>
                    <li class="border-l-2 border-brand pl-3">“Scaffold a Laravilt plugin that adds a dashboard widget.”</li>
                    <li class="border-l-2 border-accent pl-3">“Which laravilt/* versions should my composer.json require?”</li>
                </ul>
            </div>
            <div class="px-6 py-10">
                <h2 class="text-2xl font-semibold tracking-tight">Raw JSON-RPC</h2>
                <pre class="mt-4 overflow-x-auto bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-100"><code>curl -s {{ $endpoint }} \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call",
       "params":{"name":"get-latest-versions","arguments":{}}}'</code></pre>
            </div>
        </div>
    </section>
</main>

<footer>
    <div class="mx-auto flex max-w-5xl flex-col gap-2 border-x border-zinc-200 px-6 py-8 text-sm text-zinc-500 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
        <p>Open source under the MIT license · <a href="https://github.com/laravilt/mcp" class="hover:text-zinc-900 dark:hover:text-white">laravilt/mcp</a></p>
        <p class="font-mono text-xs"><a href="https://laravilt.com" class="hover:text-brand">laravilt.com</a> · <a href="/up" class="hover:text-brand">status</a></p>
    </div>
</footer>

<script>
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const text = document.getElementById(button.dataset.copy).innerText;
            try {
                await navigator.clipboard.writeText(text);
                const label = button.textContent;
                button.textContent = 'Copied';
                setTimeout(() => (button.textContent = label), 1500);
            } catch (e) {}
        });
    });

    document.querySelectorAll('[data-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('[data-tab]').forEach((t) => t.setAttribute('aria-selected', String(t === tab)));
            document.querySelectorAll('[data-panel]').forEach((panel) => panel.classList.toggle('hidden', panel.dataset.panel !== tab.dataset.tab));
        });
    });
</script>
</body>
</html>

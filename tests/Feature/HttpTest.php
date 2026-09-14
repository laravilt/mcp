<?php

use App\Mcp\Servers\LaraviltServer;
use App\Models\Document;

function rpc(string $method, array $params = [], int $id = 1): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
}

it('renders the landing page', function () {
    config()->set('app.url', 'https://mcp.laravilt.com');
    syncLaravilt();

    $this->get('/')
        ->assertOk()
        ->assertSee('Laravilt')
        ->assertSee('claude mcp add --transport http laravilt https://mcp.laravilt.com/mcp', false)
        ->assertSee('mcp-remote')
        ->assertSee('laravilt/panel')
        ->assertSee('1.1.0')
        ->assertSee('search-docs')
        ->assertSee('plugin-development-guide')
        ->assertSee('Last sync');
});

it('renders the landing page before the first sync', function () {
    $this->get('/')->assertOk()->assertSee('No packages synced yet');
});

it('exposes a health check', function () {
    $this->get('/up')->assertOk();
});

it('speaks JSON-RPC over streamable HTTP', function () {
    syncLaravilt();

    $this->postJson('/mcp', rpc('initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
    ]))
        ->assertOk()
        ->assertHeader('MCP-Session-Id')
        ->assertJsonPath('result.serverInfo.name', 'Laravilt MCP')
        ->assertJsonPath('result.capabilities.tools.listChanged', false);

    $tools = $this->postJson('/mcp', rpc('tools/list'))->assertOk()->json('result.tools');

    expect(collect($tools)->pluck('name')->all())->toBe([
        'list-packages', 'get-package', 'search-docs', 'read-doc', 'list-docs',
        'get-latest-versions', 'get-changelog', 'plugin-development-guide', 'installation-guide',
    ]);

    $search = collect($tools)->firstWhere('name', 'search-docs');
    expect($search['inputSchema']['required'])->toBe(['query'])
        ->and($search['annotations'])->toMatchArray(['readOnlyHint' => true, 'idempotentHint' => true]);

    $this->postJson('/mcp', rpc('tools/call', ['name' => 'get-latest-versions', 'arguments' => (object) []]))
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.versions.laravilt/panel', '1.1.0');
});

it('lists every document as a resource and reads nested paths', function () {
    syncLaravilt();

    $response = $this->postJson('/mcp', rpc('resources/list'))->assertOk();
    $uris = collect($response->json('result.resources'))->pluck('uri');

    expect($uris->first())->toBe('laravilt://packages')
        ->and($uris)->toContain('laravilt://docs/laravilt/docs/getting-started/installation.md', 'laravilt://docs/panel/README.md')
        ->and($uris->count())->toBe(1 + Document::count());

    $this->postJson('/mcp', rpc('resources/templates/list'))
        ->assertOk()
        ->assertJsonPath('result.resourceTemplates.0.uriTemplate', 'laravilt://docs/{package}/{path}');

    $this->postJson('/mcp', rpc('resources/read', ['uri' => 'laravilt://docs/laravilt/docs/getting-started/installation.md']))
        ->assertOk()
        ->assertJsonPath('result.contents.0.uri', 'laravilt://docs/laravilt/docs/getting-started/installation.md')
        ->assertJsonPath('result.contents.0.mimeType', 'text/markdown')
        ->assertJsonPath('result.contents.0._meta.title', 'Installation');

    $this->postJson('/mcp', rpc('resources/read', ['uri' => 'laravilt://docs/panel/nope.md']))
        ->assertJsonPath('error.message', 'Document [nope.md] was not found in laravilt/panel.');

    $this->postJson('/mcp', rpc('prompts/list'))
        ->assertOk()
        ->assertJsonPath('result.prompts.0.name', 'build-resource');
});

it('paginates the resource list', function () {
    syncLaravilt();

    $first = $this->postJson('/mcp', rpc('resources/list', ['per_page' => 10]))->assertOk();

    expect($first->json('result.resources'))->toHaveCount(10)
        ->and($first->json('result.nextCursor'))->not->toBeNull();

    $second = $this->postJson('/mcp', rpc('resources/list', ['per_page' => 10, 'cursor' => $first->json('result.nextCursor')]));

    expect($second->json('result.resources.0.uri'))->not->toBe($first->json('result.resources.0.uri'));
});

it('rate limits MCP requests per IP', function () {
    config()->set('laravilt.rate_limit.per_minute', 3);

    foreach (range(1, 3) as $i) {
        $this->postJson('/mcp', rpc('ping', [], $i))->assertOk()->assertHeader('X-RateLimit-Limit', '3');
    }

    $this->postJson('/mcp', rpc('ping', [], 4))
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error.code', -32000)
        ->assertJsonPath('id', 4);

    // Another client is not affected.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/mcp', rpc('ping', [], 5))
        ->assertOk();
});

it('rejects GET on the MCP endpoint', function () {
    $this->get('/mcp')->assertStatus(405);
});

it('keeps tool names in sync with the landing page constant', function () {
    expect(LaraviltServer::TOOLS)->toHaveCount(9);
});

<?php

use App\Mcp\Servers\LaraviltServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Server
|--------------------------------------------------------------------------
|
| Public, read-only streamable HTTP endpoint. Rate limited per IP address
| (see LARAVILT_RATE_LIMIT and AppServiceProvider).
|
*/

Mcp::web('/'.trim((string) config('laravilt.server.path', 'mcp'), '/'), LaraviltServer::class)
    ->middleware('throttle:mcp')
    ->name('mcp');

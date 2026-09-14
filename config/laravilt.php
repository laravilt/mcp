<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    |
    | Public name and version advertised to MCP clients during initialization.
    |
    */

    'server' => [
        'name' => 'Laravilt MCP',
        'version' => env('LARAVILT_MCP_VERSION', '1.0.0'),
        'path' => env('LARAVILT_MCP_PATH', 'mcp'),
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub
    |--------------------------------------------------------------------------
    |
    | The organization that is scanned for Laravilt packages. A token is only
    | needed to raise the API rate limit. The webhook secret is required to
    | accept push events on POST /webhooks/github.
    |
    */

    'github' => [
        'org' => env('LARAVILT_GITHUB_ORG', 'laravilt'),
        'token' => env('GITHUB_TOKEN'),
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'raw_url' => env('GITHUB_RAW_URL', 'https://raw.githubusercontent.com'),
        'timeout' => (int) env('GITHUB_TIMEOUT', 60),

        // Repositories that are never treated as packages.
        'exclude' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'LARAVILT_EXCLUDED_REPOS',
            'mcp,laravilt.com,laravilt-dev,.github',
        ))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Packagist
    |--------------------------------------------------------------------------
    */

    'packagist' => [
        'url' => env('PACKAGIST_URL', 'https://repo.packagist.org'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync
    |--------------------------------------------------------------------------
    |
    | How often (in minutes) the scheduler runs `laravilt:sync`, and the upper
    | bound for a single synced file.
    |
    */

    'sync' => [
        'interval' => (int) env('LARAVILT_SYNC_INTERVAL', 30),
        'max_file_size' => (int) env('LARAVILT_SYNC_MAX_FILE_SIZE', 512 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | "auto" uses SQLite FTS5 when the documents_fts table exists and falls back
    | to a portable LIKE based ranking otherwise. Force either with fts|like.
    |
    */

    'search' => [
        'driver' => env('LARAVILT_SEARCH_DRIVER', 'auto'),
        'max_limit' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    |
    | Long documents are split into pages of roughly this many characters by
    | the read-doc tool.
    |
    */

    'docs' => [
        'page_size' => (int) env('LARAVILT_DOC_PAGE_SIZE', 16000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Maximum MCP requests per minute for a single client IP address.
    |
    */

    'rate_limit' => [
        'per_minute' => (int) env('LARAVILT_RATE_LIMIT', 120),
    ],

    'trusted_proxies' => env('TRUSTED_PROXIES'),

];

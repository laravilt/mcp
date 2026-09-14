![Laravilt](https://raw.githubusercontent.com/laravilt/laravilt/master/arts/hero.jpg)

# Laravilt MCP

A public, read-only [Model Context Protocol](https://modelcontextprotocol.io) server that gives AI assistants the **latest Laravilt documentation, READMEs, changelogs and released versions** of every `laravilt/*` package, including the plugin system and the Vue/React frontends.

Endpoint: **`https://mcp.laravilt.com/mcp`** (streamable HTTP, no authentication, rate limited per IP).

## Connect

**Claude Code**

```bash
claude mcp add --transport http laravilt https://mcp.laravilt.com/mcp
```

**Cursor / VS Code** (`.cursor/mcp.json` or `.vscode/mcp.json`)

```json
{
    "mcpServers": {
        "laravilt": { "type": "http", "url": "https://mcp.laravilt.com/mcp" }
    }
}
```

**Claude Desktop** (through [`mcp-remote`](https://www.npmjs.com/package/mcp-remote))

```json
{
    "mcpServers": {
        "laravilt": { "command": "npx", "args": ["-y", "mcp-remote", "https://mcp.laravilt.com/mcp"] }
    }
}
```

Any other client: use the streamable HTTP URL `https://mcp.laravilt.com/mcp`.

## Tools

| Tool | What it returns |
|---|---|
| `list-packages` | Every Laravilt package with description, latest version, release date, install command and repository |
| `get-package` | One package: README summary, latest version, requirements, frontend notes (Vue and React), docs index, recent changelog |
| `search-docs` | Ranked documentation snippets with paths and GitHub links (optionally scoped to a package) |
| `read-doc` | A full document, paginated for long pages |
| `list-docs` | The documentation tree, for one package or all |
| `get-latest-versions` | A `package => version` map and a ready-to-paste `composer require` line |
| `get-changelog` | Recent changelog entries for a package |
| `plugin-development-guide` | How to build Laravilt plugins, assembled from the `laravilt/plugins` docs |
| `installation-guide` | Installing Laravilt on Laravel 13 with `php artisan laravilt:install --stack=vue\|react` |

**Resources:** `laravilt://packages` and `laravilt://docs/{package}/{path}` for every synced document. **Prompt:** `build-resource`.

## How content stays current

`php artisan laravilt:sync` scans the `laravilt` GitHub organization, downloads each package's default branch as a tarball, indexes `README.md`, `CHANGELOG.md`, `composer.json` and every Markdown file under `docs/`, and records released versions from Packagist. Unchanged commits are skipped. The scheduler runs it every 30 minutes, and a GitHub webhook (`POST /webhooks/github`, signed with `GITHUB_WEBHOOK_SECRET`) queues a sync of the pushed repository immediately.

## Local development

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan laravilt:sync
php artisan serve
```

Then open http://127.0.0.1:8000 for the landing page, or point an MCP client at http://127.0.0.1:8000/mcp.

```bash
php artisan test
vendor/bin/pint
```

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `APP_URL` | | Public URL, e.g. `https://mcp.laravilt.com` |
| `LARAVILT_GITHUB_ORG` | `laravilt` | Organization scanned for packages |
| `LARAVILT_EXCLUDED_REPOS` | `mcp,laravilt.com,laravilt-dev,.github` | Repositories that are not packages |
| `GITHUB_TOKEN` | | Optional; raises the GitHub API rate limit |
| `GITHUB_WEBHOOK_SECRET` | | Required to accept push webhooks |
| `LARAVILT_SYNC_INTERVAL` | `30` | Minutes between scheduled syncs |
| `LARAVILT_SEARCH_DRIVER` | `auto` | `auto`, `fts` (SQLite FTS5) or `like` |
| `LARAVILT_DOC_PAGE_SIZE` | `16000` | Characters per `read-doc` page |
| `LARAVILT_RATE_LIMIT` | `120` | MCP requests per minute per IP |
| `LARAVILT_MCP_PATH` | `mcp` | Path of the MCP endpoint |
| `TRUSTED_PROXIES` | | Proxy IPs/CIDRs (set when behind a reverse proxy) |

## Deployment

1. `composer install --no-dev --optimize-autoloader`, create `.env` from `.env.example`, `php artisan key:generate`, `php artisan migrate --force`.
2. Run `php artisan laravilt:sync` once.
3. Run the scheduler every minute (`php artisan schedule:run`) and a queue worker for webhook-triggered syncs (`php artisan queue:work`, or `QUEUE_CONNECTION=sync`).
4. Serve `public/` with PHP-FPM behind your web server or reverse proxy, and set `TRUSTED_PROXIES`.
5. In each Laravilt repository (or the organization), add a webhook to `https://mcp.laravilt.com/webhooks/github` for push events, using the same secret.

## License

MIT

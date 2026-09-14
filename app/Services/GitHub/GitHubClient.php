<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GitHubClient
{
    public function __construct(
        protected string $apiUrl,
        protected string $rawUrl,
        protected ?string $token = null,
        protected int $timeout = 60,
    ) {
        //
    }

    public static function fromConfig(): self
    {
        return new self(
            apiUrl: rtrim((string) config('laravilt.github.api_url'), '/'),
            rawUrl: rtrim((string) config('laravilt.github.raw_url'), '/'),
            token: config('laravilt.github.token') ?: null,
            timeout: (int) config('laravilt.github.timeout', 60),
        );
    }

    /**
     * All public repositories of an organization.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    public function organizationRepositories(string $org): array
    {
        $repositories = [];

        for ($page = 1; $page <= 20; $page++) {
            $batch = $this->api()
                ->get("/orgs/{$org}/repos", ['type' => 'public', 'per_page' => 100, 'page' => $page])
                ->throw()
                ->json();

            if (! is_array($batch) || $batch === []) {
                break;
            }

            array_push($repositories, ...$batch);

            if (count($batch) < 100) {
                break;
            }
        }

        return $repositories;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function repository(string $org, string $repo): array
    {
        return (array) $this->api()->get("/repos/{$org}/{$repo}")->throw()->json();
    }

    /**
     * The commit SHA at the head of a branch.
     *
     * @throws RequestException
     */
    public function headSha(string $org, string $repo, string $branch): string
    {
        $response = $this->api()
            ->replaceHeaders(['Accept' => 'application/vnd.github.sha'])
            ->get("/repos/{$org}/{$repo}/commits/".rawurlencode($branch))
            ->throw();

        $body = trim($response->body());

        return str_starts_with($body, '{') ? (string) $response->json('sha') : $body;
    }

    /**
     * A single file at a given ref, or null when it does not exist.
     *
     * @throws RequestException
     */
    public function rawFile(string $org, string $repo, string $ref, string $path): ?string
    {
        $response = $this->http()
            ->get("{$this->rawUrl}/{$org}/{$repo}/{$ref}/".ltrim($path, '/'));

        if ($response->notFound()) {
            return null;
        }

        return $response->throw()->body();
    }

    /**
     * Download the tarball of a ref to a temporary file and return its path.
     *
     * @throws RequestException
     */
    public function downloadTarball(string $org, string $repo, string $ref): string
    {
        $response = $this->api()
            ->timeout(max($this->timeout, 120))
            ->get("/repos/{$org}/{$repo}/tarball/".rawurlencode($ref))
            ->throw();

        $path = tempnam(sys_get_temp_dir(), 'laravilt-tarball-');
        file_put_contents($path, $response->body());

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    public function releases(string $org, string $repo, int $perPage = 30): array
    {
        $response = $this->api()->get("/repos/{$org}/{$repo}/releases", ['per_page' => $perPage]);

        return $this->listOrEmpty($response);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    public function tags(string $org, string $repo, int $perPage = 30): array
    {
        $response = $this->api()->get("/repos/{$org}/{$repo}/tags", ['per_page' => $perPage]);

        return $this->listOrEmpty($response);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    protected function listOrEmpty(Response $response): array
    {
        if ($response->notFound()) {
            return [];
        }

        $data = $response->throw()->json();

        return is_array($data) ? array_values($data) : [];
    }

    protected function api(): PendingRequest
    {
        return $this->http()
            ->baseUrl($this->apiUrl)
            ->replaceHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);
    }

    protected function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->connectTimeout(15)
            ->retry(2, 500, fn ($exception): bool => $exception instanceof ConnectionException, throw: false)
            ->withUserAgent('laravilt-mcp (+https://mcp.laravilt.com)')
            ->when($this->token, fn (PendingRequest $request, string $token) => $request->withToken($token));
    }
}

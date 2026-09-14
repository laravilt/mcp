<?php

namespace App\Providers;

use App\Services\GitHub\GitHubClient;
use App\Services\Packagist\PackagistClient;
use App\Services\Search\DocumentSearch;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GitHubClient::class, fn (): GitHubClient => GitHubClient::fromConfig());
        $this->app->bind(PackagistClient::class, fn (): PackagistClient => PackagistClient::fromConfig());
        $this->app->scoped(DocumentSearch::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind a reverse proxy or load balancer set TRUSTED_PROXIES ("*" or a
        // comma separated list) so rate limiting sees the real client IP.
        if ($proxies = config('laravilt.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)));
        }

        RateLimiter::for('mcp', function (Request $request): Limit {
            $perMinute = max(1, (int) config('laravilt.rate_limit.per_minute', 120));

            return Limit::perMinute($perMinute)
                ->by('mcp|'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $request->json('id'),
                    'error' => [
                        'code' => -32000,
                        'message' => "Rate limit exceeded: {$perMinute} requests per minute. Retry after {$headers['Retry-After']} seconds.",
                    ],
                ], 429, $headers));
        });
    }
}

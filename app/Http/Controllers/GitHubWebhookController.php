<?php

namespace App\Http\Controllers;

use App\Jobs\SyncRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GitHubWebhookController
{
    /**
     * Events that can change documentation or versions.
     */
    protected const SYNC_EVENTS = ['push', 'release', 'create', 'delete', 'repository', 'public'];

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('laravilt.github.webhook_secret');

        if ($secret === '') {
            return response()->json(['message' => 'Webhooks are not configured.'], 503);
        }

        if (! static::validSignature($request->getContent(), (string) $request->header('X-Hub-Signature-256'), $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = (string) $request->header('X-GitHub-Event');

        if ($event === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        if (! in_array($event, self::SYNC_EVENTS, true)) {
            return response()->json(['message' => "Event [{$event}] ignored."], 202);
        }

        // GitHub sends either application/json or a form encoded "payload" field.
        $payload = $request->isJson()
            ? (array) $request->json()->all()
            : (array) json_decode((string) $request->input('payload', '{}'), true);

        $owner = Str::lower((string) data_get($payload, 'repository.owner.login', Str::before((string) data_get($payload, 'repository.full_name'), '/')));
        $repository = (string) data_get($payload, 'repository.name');

        if ($repository === '' || $owner !== Str::lower((string) config('laravilt.github.org'))) {
            return response()->json(['message' => 'Repository ignored.'], 202);
        }

        // Only pushes to the default branch change the synced content.
        if ($event === 'push') {
            $defaultBranch = (string) data_get($payload, 'repository.default_branch', 'main');
            $ref = (string) data_get($payload, 'ref');

            if ($ref !== 'refs/heads/'.$defaultBranch && ! str_starts_with($ref, 'refs/tags/')) {
                return response()->json(['message' => 'Push to a non-default branch ignored.'], 202);
            }
        }

        if (config('queue.default') === 'sync') {
            // No worker: answer GitHub first, then sync.
            SyncRepository::dispatchAfterResponse($repository, 'webhook');
        } else {
            SyncRepository::dispatch($repository, 'webhook');
        }

        return response()->json(['message' => "Sync of {$repository} queued."], 202);
    }

    public static function validSignature(string $payload, string $signature, string $secret): bool
    {
        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature);
    }
}

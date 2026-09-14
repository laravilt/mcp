<?php

use App\Jobs\SyncRepository;
use App\Models\Package;
use App\Services\Sync\Synchronizer;
use Illuminate\Support\Facades\Queue;

function webhook(array $payload, string $event = 'push', ?string $secret = 'webhook-secret', ?string $signature = null)
{
    $body = json_encode($payload);
    $signature ??= 'sha256='.hash_hmac('sha256', $body, (string) $secret);

    return test()->call('POST', '/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $body);
}

function pushPayload(string $repo = 'panel', string $ref = 'refs/heads/main'): array
{
    return [
        'ref' => $ref,
        'repository' => [
            'name' => $repo,
            'full_name' => "laravilt/{$repo}",
            'default_branch' => 'main',
            'owner' => ['login' => 'laravilt'],
        ],
    ];
}

beforeEach(function () {
    config()->set('laravilt.github.webhook_secret', 'webhook-secret');
    config()->set('queue.default', 'database');
    Queue::fake();
});

it('queues a sync for a signed push to the default branch', function () {
    webhook(pushPayload())
        ->assertStatus(202)
        ->assertJson(['message' => 'Sync of panel queued.']);

    Queue::assertPushed(SyncRepository::class, fn (SyncRepository $job) => $job->repository === 'panel');
});

it('rejects invalid or missing signatures', function () {
    webhook(pushPayload(), signature: 'sha256='.str_repeat('0', 64))->assertStatus(401);
    webhook(pushPayload(), signature: '')->assertStatus(401);
    webhook(pushPayload(), secret: 'wrong-secret')->assertStatus(401);

    Queue::assertNothingPushed();
});

it('refuses webhooks when no secret is configured', function () {
    config()->set('laravilt.github.webhook_secret', null);

    webhook(pushPayload())->assertStatus(503);

    Queue::assertNothingPushed();
});

it('answers ping events', function () {
    webhook(['zen' => 'Keep it logically awesome.'], 'ping')->assertOk()->assertJson(['message' => 'pong']);
});

it('ignores pushes to other branches, other owners and unrelated events', function () {
    webhook(pushPayload(ref: 'refs/heads/feature'))->assertStatus(202)->assertJson(['message' => 'Push to a non-default branch ignored.']);

    $foreign = pushPayload();
    $foreign['repository']['owner']['login'] = 'someone-else';
    webhook($foreign)->assertStatus(202)->assertJson(['message' => 'Repository ignored.']);

    webhook(pushPayload(), 'issues')->assertStatus(202);

    Queue::assertNothingPushed();
});

it('queues a sync for releases and tag pushes', function () {
    webhook(pushPayload('forms'), 'release')->assertStatus(202);
    webhook(pushPayload('tables', ref: 'refs/tags/v1.2.0'))->assertStatus(202);

    Queue::assertPushed(SyncRepository::class, 2);
});

it('accepts form encoded payloads', function () {
    $body = http_build_query(['payload' => json_encode(pushPayload('forms'))]);

    $this->call('POST', '/webhooks/github', ['payload' => json_encode(pushPayload('forms'))], [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'webhook-secret'),
    ], $body)->assertStatus(202);

    Queue::assertPushed(SyncRepository::class, fn (SyncRepository $job) => $job->repository === 'forms');
});

it('syncs the pushed repository when the job runs', function () {
    fakeLaravilt();

    (new SyncRepository('panel'))->handle(app(Synchronizer::class));

    expect(Package::query()->pluck('name')->all())->toBe(['laravilt/panel']);
});

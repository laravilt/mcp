<?php

use App\Models\SyncRun;
use App\Services\Sync\Synchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLaravilt;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Fake the laravilt GitHub organization and Packagist.
 */
function fakeLaravilt(?FakeLaravilt $fake = null): FakeLaravilt
{
    config()->set('laravilt.github.org', 'laravilt');
    config()->set('laravilt.github.exclude', ['mcp', 'laravilt.com', 'laravilt-dev', '.github']);

    return ($fake ?? FakeLaravilt::organization())->fake();
}

/**
 * Fake the organization and run a full sync.
 */
function syncLaravilt(?FakeLaravilt $fake = null): SyncRun
{
    fakeLaravilt($fake);

    return app(Synchronizer::class)->syncAll();
}

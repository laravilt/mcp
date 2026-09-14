<?php

use App\Models\Document;
use App\Models\Package;
use App\Models\Release;
use App\Models\Repository;
use App\Services\Search\DocumentSearch;
use App\Services\Sync\Synchronizer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeLaravilt;

function tarballRequests(): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), '/tarball/'))->count();
}

it('syncs new packages, documents and versions', function () {
    $run = syncLaravilt();

    expect($run->status)->toBe('completed')
        ->and($run->updated)->toBe(4)
        ->and($run->packages)->toBe(4)
        ->and($run->errors)->toBeNull();

    // Excluded repositories and non-Laravilt composer packages are not packages.
    expect(Package::query()->orderBy('name')->pluck('name')->all())
        ->toBe(['laravilt/forms', 'laravilt/laravilt', 'laravilt/panel', 'laravilt/plugins']);
    expect(Repository::query()->where('name', 'skeleton')->value('is_package'))->toBeFalse();
    expect(Repository::query()->where('name', 'mcp')->exists())->toBeFalse();

    $panel = Package::findByIdentifier('panel');

    expect($panel->documents()->orderBy('path')->pluck('path')->all())->toBe([
        'CHANGELOG.md',
        'README.md',
        'composer.json',
        'docs/index.md',
        'docs/relation-managers.md',
        'package.json',
    ])
        ->and($panel->has_vue)->toBeTrue()
        ->and($panel->has_react)->toBeTrue()
        ->and($panel->latest_version)->toBe('1.1.0') // the beta is ignored
        ->and($panel->latest_release_at->toDateString())->toBe('2026-08-01')
        ->and($panel->sha)->toBe(sha1('panel-1'))
        ->and($panel->stars)->toBe(7);

    $forms = Package::findByIdentifier('laravilt/forms');
    expect($forms->has_vue)->toBeTrue()->and($forms->has_react)->toBeFalse();

    $doc = Document::query()->where('path', 'docs/relation-managers.md')->first();
    expect($doc->title)->toBe('Relation Managers')
        ->and($doc->headings)->toBe(['Creating a Relation Manager'])
        ->and($doc->source_url)->toBe('https://github.com/laravilt/panel/blob/main/docs/relation-managers.md')
        ->and($doc->sha)->toBe(sha1('panel-1'))
        ->and($doc->synced_at)->not->toBeNull();

    $release = Release::query()->where('package_id', $panel->id)->where('version', '1.1.0')->first();
    expect($release->on_packagist)->toBeTrue()
        ->and($release->on_github)->toBeTrue()
        ->and($release->body)->toBe('Adds relation manager improvements.');

    expect(DB::table('documents_fts')->count())->toBe(Document::query()->count());
    expect(tarballRequests())->toBe(4);
});

it('skips repositories whose push date and SHA did not change', function () {
    $fake = FakeLaravilt::organization();
    syncLaravilt($fake);
    $syncedAt = Package::findByIdentifier('panel')->content_synced_at;

    $this->travel(5)->minutes();
    $fake->fake(); // also resets the recorded requests

    $run = app(Synchronizer::class)->syncAll();

    expect($run->updated)->toBe(0)
        ->and($run->skipped)->toBe(4)
        ->and(tarballRequests())->toBe(0)
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), '/commits/'))->count())->toBe(0)
        ->and(Package::findByIdentifier('panel')->content_synced_at->equalTo($syncedAt))->toBeTrue();
});

it('does not download content when only the push date changed', function () {
    $fake = FakeLaravilt::organization();
    syncLaravilt($fake);

    $fake->repositories['panel']['pushed_at'] = '2026-09-12T00:00:00Z'; // e.g. a pushed branch
    $fake->fake();

    $run = app(Synchronizer::class)->syncAll();

    expect($run->updated)->toBe(0)
        ->and($run->skipped)->toBe(4)
        ->and(tarballRequests())->toBe(0)
        ->and(Repository::query()->where('name', 'panel')->first()->pushed_at->toDateString())->toBe('2026-09-12');
});

it('updates, adds and removes documents when the SHA changes', function () {
    $fake = FakeLaravilt::organization();
    syncLaravilt($fake);

    $removedId = Document::query()->where('path', 'docs/relation-managers.md')->value('id');

    $fake->push('panel', [
        'docs/index.md' => "# Panel v2\n\nNow with tenancy support.\n",
        'docs/tenancy.md' => "# Tenancy\n\nMulti tenancy for panels.\n",
        'docs/relation-managers.md' => null,
        'resources/react/app.tsx' => null,
    ], sha: str_repeat('b', 40));
    $fake->fake();

    $run = app(Synchronizer::class)->syncAll();

    $panel = Package::findByIdentifier('panel');

    expect($run->updated)->toBe(1)
        ->and($run->skipped)->toBe(3)
        ->and($panel->sha)->toBe(str_repeat('b', 40))
        ->and($panel->has_react)->toBeFalse()
        ->and($panel->documents()->pluck('path')->all())->toContain('docs/tenancy.md')->not->toContain('docs/relation-managers.md')
        ->and($panel->documents()->where('path', 'docs/index.md')->value('title'))->toBe('Panel v2')
        ->and($panel->documents()->where('path', 'README.md')->value('sha'))->toBe(str_repeat('b', 40))
        ->and(DB::table('documents_fts')->where('rowid', $removedId)->exists())->toBeFalse()
        ->and(DB::table('documents_fts')->count())->toBe(Document::query()->count());

    $hits = app(DocumentSearch::class)->search('tenancy');
    expect($hits)->not->toBeEmpty()->and($hits[0]['document']->path)->toBe('docs/tenancy.md');
});

it('removes packages that disappear from the organization', function () {
    $fake = FakeLaravilt::organization();
    syncLaravilt($fake);

    $fake->repositories['forms']['archived'] = true;
    $fake->fake();

    app(Synchronizer::class)->syncAll();

    expect(Package::findByIdentifier('forms'))->toBeNull()
        ->and(Document::query()->whereHas('package', fn ($q) => $q->where('repo', 'forms'))->count())->toBe(0)
        ->and(DB::table('documents_fts')->count())->toBe(Document::query()->count());
});

it('forces a full re-download with --force', function () {
    $fake = FakeLaravilt::organization();
    syncLaravilt($fake);

    $fake->fake();

    $this->artisan('laravilt:sync', ['--force' => true])
        ->expectsOutputToContain('panel')
        ->assertSuccessful();

    expect(tarballRequests())->toBe(4);
});

it('syncs a single repository', function () {
    fakeLaravilt();

    $this->artisan('laravilt:sync', ['repository' => 'forms'])
        ->expectsOutputToContain('forms')
        ->assertSuccessful();

    expect(Package::query()->pluck('name')->all())->toBe(['laravilt/forms']);
});

it('records failures without aborting the whole sync', function () {
    $fake = FakeLaravilt::organization();

    // Registered first, so it wins over the organization fake.
    Http::fake([
        'api.github.com/repos/laravilt/forms/tarball/*' => Http::response('boom', 500),
    ]);
    fakeLaravilt($fake);

    $run = app(Synchronizer::class)->syncAll();

    expect($run->status)->toBe('completed_with_errors')
        ->and($run->errors)->toHaveKey('forms')
        ->and(Package::findByIdentifier('panel'))->not->toBeNull();
});

it('schedules the sync', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'laravilt:sync'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/30 * * * *');
});

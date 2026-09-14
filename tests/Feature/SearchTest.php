<?php

use App\Models\Package;
use App\Services\Search\DocumentSearch;

dataset('drivers', ['fts', 'like']);

beforeEach(function () {
    syncLaravilt();
});

it('uses FTS5 when available', function () {
    expect(app(DocumentSearch::class)->driver())->toBe('fts');

    config()->set('laravilt.search.driver', 'like');

    expect(app(DocumentSearch::class)->driver())->toBe('like');
});

it('ranks title matches above passing mentions', function (string $driver) {
    config()->set('laravilt.search.driver', $driver);

    $results = app(DocumentSearch::class)->search('relation managers');

    expect($results)->not->toBeEmpty()
        ->and($results[0]['document']->path)->toBe('docs/relation-managers.md')
        ->and($results[0]['document']->package->name)->toBe('laravilt/panel')
        ->and($results[0]['snippet'])->toContain('**');
})->with('drivers');

it('prefers documentation over composer.json', function (string $driver) {
    config()->set('laravilt.search.driver', $driver);

    $paths = collect(app(DocumentSearch::class)->search('forms'))->map(fn ($r) => $r['document']->path);

    expect($paths->first())->not->toBe('composer.json')
        ->and($paths->search('composer.json'))->toBeGreaterThan($paths->search('docs/index.md'));
})->with('drivers');

it('filters by package and respects the limit', function (string $driver) {
    config()->set('laravilt.search.driver', $driver);

    $forms = Package::findByIdentifier('forms');
    $results = app(DocumentSearch::class)->search('laravilt', $forms->id, 2);

    expect($results)->toHaveCount(2)
        ->and(collect($results)->every(fn ($r) => $r['document']->package_id === $forms->id))->toBeTrue();
})->with('drivers');

it('falls back to matching any term when all terms do not match', function () {
    $results = app(DocumentSearch::class)->search('tenancy relation');

    expect($results)->not->toBeEmpty()
        ->and($results[0]['document']->path)->toBe('docs/relation-managers.md');
});

it('handles punctuation and FTS syntax in queries safely', function (string $driver) {
    config()->set('laravilt.search.driver', $driver);

    expect(app(DocumentSearch::class)->search('"TextInput" AND (NEAR*'))->not->toBeEmpty()
        ->and(app(DocumentSearch::class)->search('!!! ???'))->toBe([]);
})->with('drivers');

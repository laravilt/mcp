<?php

namespace App\Console\Commands;

use App\Services\Search\DocumentSearch;
use App\Services\Sync\Synchronizer;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'laravilt:sync
                            {repository? : Only sync this repository (e.g. "panel")}
                            {--force : Re-download content even when the commit SHA is unchanged}
                            {--reindex : Rebuild the full-text search index after syncing}
                            {--trigger=manual : Recorded as the trigger of the sync run}';

    protected $description = 'Sync Laravilt package documentation and versions from GitHub and Packagist';

    public function handle(Synchronizer $synchronizer, DocumentSearch $search): int
    {
        $synchronizer->withOutput(fn (string $line) => $this->line('  '.$line));

        $repository = $this->argument('repository');
        $force = (bool) $this->option('force');
        $trigger = (string) $this->option('trigger');

        $this->components->info($repository
            ? "Syncing laravilt/{$repository}…"
            : 'Syncing the '.config('laravilt.github.org').' GitHub organization');

        $run = $repository
            ? $synchronizer->syncRepository((string) $repository, $force, $trigger)
            : $synchronizer->syncAll($force, $trigger);

        if ($this->option('reindex')) {
            $this->line('  Rebuilt search index for '.$search->rebuild().' documents');
        }

        $this->newLine();
        $this->components->twoColumnDetail('Status', $run->status);
        $this->components->twoColumnDetail('Repositories checked', (string) $run->repositories);
        $this->components->twoColumnDetail('Packages updated', (string) $run->updated);
        $this->components->twoColumnDetail('Packages unchanged', (string) $run->skipped);
        $this->components->twoColumnDetail('Packages indexed', (string) $run->packages);
        $this->components->twoColumnDetail('Documents indexed', (string) $run->documents);
        $this->components->twoColumnDetail('Duration', $run->started_at->diffForHumans($run->finished_at, true));

        foreach ((array) $run->errors as $name => $error) {
            $this->components->error("{$name}: {$error}");
        }

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}

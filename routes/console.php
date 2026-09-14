<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled documentation sync
|--------------------------------------------------------------------------
|
| Runs `laravilt:sync` every LARAVILT_SYNC_INTERVAL minutes (default 30).
| Unchanged repositories are skipped, so frequent runs are cheap.
|
*/

$interval = max(1, (int) config('laravilt.sync.interval', 30));

$expression = $interval < 60
    ? "*/{$interval} * * * *"
    : '0 */'.max(1, min(23, intdiv($interval, 60))).' * * *';

Schedule::command('laravilt:sync', ['--trigger' => 'schedule'])
    ->cron($expression)
    ->withoutOverlapping(30)
    ->runInBackground()
    ->name('laravilt-sync');

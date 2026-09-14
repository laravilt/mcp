<?php

namespace App\Jobs;

use App\Services\Sync\Synchronizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 120;

    public function __construct(
        public string $repository,
        public string $trigger = 'webhook',
    ) {
        //
    }

    public function uniqueId(): string
    {
        return $this->repository;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(Synchronizer $synchronizer): void
    {
        $synchronizer->syncRepository($this->repository, force: false, trigger: $this->trigger);
    }
}

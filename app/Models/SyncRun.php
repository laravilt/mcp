<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function lastFinished(): ?self
    {
        return static::query()
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->latest('id')
            ->first();
    }
}

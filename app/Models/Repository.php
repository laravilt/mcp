<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Repository extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_package' => 'boolean',
            'pushed_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}

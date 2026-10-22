<?php

namespace App\Modules\Workspace\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasUuids;

    protected $table = 'workspaces';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['preferences' => 'array', 'expires_at' => 'datetime'];
    }
}

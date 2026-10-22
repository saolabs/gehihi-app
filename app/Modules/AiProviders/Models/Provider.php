<?php

namespace App\Modules\AiProviders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    use HasUuids, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return ['secrets' => 'encrypted:array', 'models' => 'array', 'capabilities' => 'array', 'enabled' => 'boolean', 'is_default' => 'boolean', 'tested_at' => 'datetime'];
    }
}

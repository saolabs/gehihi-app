<?php

namespace App\Modules\Workspace\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'projects';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['selection' => 'array', 'archived' => 'boolean'];
    }
}

<?php

namespace App\Modules\Workspace\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SourceFile extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'files';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}

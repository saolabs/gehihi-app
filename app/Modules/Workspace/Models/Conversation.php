<?php

namespace App\Modules\Workspace\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'conversations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['selection' => 'array', 'pinned' => 'boolean', 'archived' => 'boolean', 'temporary' => 'boolean'];
    }
}

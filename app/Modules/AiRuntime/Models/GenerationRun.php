<?php

namespace App\Modules\AiRuntime\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GenerationRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $attributes = ['status' => 'queued', 'progress' => 'Đang chờ xử lý', 'used_tokens' => 0];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'checkpoint' => 'array', 'sources' => 'array', 'actual_model' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}

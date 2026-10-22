<?php

namespace App\Modules\Admin\Http;

use App\Http\Controllers\ApiController;
use App\Modules\Admin\Services\AdminStats;
use App\Modules\Admin\Services\Limits;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminApiController extends ApiController
{
    public function __construct(private AdminStats $stats) {}

    /** Khóa hạn mức dạng 'research.deep.pages' đi qua HTTP thành 'research_deep_pages' để validator không hiểu thành mảng lồng. */
    private function flat(array $values): array
    {
        return array_combine(array_map(fn ($k) => str_replace('.', '_', $k), array_keys($values)), $values);
    }

    public function overview(Request $r)
    {
        return $this->ok($this->stats->overview((int) ($r->validate(['days' => ['sometimes', Rule::in([7, 30, 90])]])['days'] ?? 30)));
    }

    public function providers()
    {
        return $this->ok($this->stats->providers());
    }

    public function users(Request $r)
    {
        return $this->ok(['users' => $this->stats->users(trim((string) $r->query('q', '')))]);
    }

    public function updateUser(Request $r, string $id)
    {
        $data = $r->validate(['is_admin' => ['required', 'boolean']]);
        abort_if($id === $r->user()->id && ! $data['is_admin'], 422, 'Không thể tự thu quyền quản trị của chính bạn.');
        User::findOrFail($id)->update($data);

        return $this->ok();
    }

    public function limits()
    {
        return $this->ok(['limits' => $this->flat(Limits::current()), 'defaults' => $this->flat(Limits::defaults())]);
    }

    public function saveLimits(Request $r)
    {
        $rules = [];
        foreach (Limits::FIELDS as $key => [$min, $max]) {
            $rules['limits.'.str_replace('.', '_', $key)] = ['required', 'integer', 'min:'.$min, 'max:'.$max];
        }
        $input = $r->validate($rules)['limits'];
        $values = [];
        foreach (array_keys(Limits::FIELDS) as $key) {
            $values[$key] = $input[str_replace('.', '_', $key)];
        }

        return $this->ok(['limits' => $this->flat(Limits::save($values)), 'defaults' => $this->flat(Limits::defaults())]);
    }
}

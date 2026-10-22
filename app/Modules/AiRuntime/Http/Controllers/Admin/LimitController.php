<?php

namespace App\Modules\AiRuntime\Http\Controllers\Admin;

use App\Http\Controllers\AdminController;
use App\Modules\AiRuntime\Services\Limits;
use Illuminate\Http\Request;

class LimitController extends AdminController
{
    public function index()
    {
        return $this->response(['groups' => Limits::groups()]);
    }

    public function update(Request $r)
    {
        $rules = [];
        foreach (Limits::FIELDS as $key => [$min, $max]) {
            $rules[Limits::field($key)] = ['required', 'integer', 'min:'.$min, 'max:'.$max];
        }
        $input = $r->validate($rules);
        Limits::save(array_combine(array_keys(Limits::FIELDS), array_map(fn ($key) => $input[Limits::field($key)], array_keys(Limits::FIELDS))));

        return $this->result(['groups' => Limits::groups()]);
    }
}

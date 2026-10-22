<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class ApiController
{
    protected function ws(Request $r): string
    {
        return $r->attributes->get('workspace')->id;
    }

    protected function ok($data = null, int $status = 200)
    {
        return response()->json(['success' => true, 'statusCode' => $status, 'data' => $data], $status);
    }

    protected function selectionRules(): array
    {
        return ['selection' => ['nullable', 'array'], 'selection.provider_id' => ['required_with:selection', 'uuid'], 'selection.model_id' => ['required_with:selection', 'string', 'max:200'], 'selection.fallbacks' => ['sometimes', 'array', 'max:3'], 'selection.fallbacks.*.provider_id' => ['required', 'uuid'], 'selection.fallbacks.*.model_id' => ['required', 'string', 'max:200']];
    }
}

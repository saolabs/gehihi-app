<?php

namespace App\Modules\AiRuntime\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Modules\AiRuntime\Services\ModelFlows;
use Illuminate\Http\Request;

class ModelFlowController extends ApiController
{
    public function __construct(private ModelFlows $flows) {}

    public function index(Request $r)
    {
        return $this->ok($this->flows->page($r->user()?->id, $this->ws($r)));
    }

    public function update(Request $r)
    {
        $data = $r->validate(ModelFlows::rules());
        return $this->ok($this->flows->save($data['flows'], $r->user()?->id, $this->ws($r)));
    }
}

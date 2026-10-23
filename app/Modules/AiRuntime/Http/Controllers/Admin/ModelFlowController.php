<?php

namespace App\Modules\AiRuntime\Http\Controllers\Admin;

use App\Http\Controllers\AdminController;
use App\Modules\AiRuntime\Services\ModelFlows;
use Illuminate\Http\Request;

class ModelFlowController extends AdminController
{
    public function __construct(private ModelFlows $flows) {}

    public function index()
    {
        return $this->response($this->flows->page());
    }

    public function update(Request $r)
    {
        $data = $r->validate(ModelFlows::rules());
        return $this->result($this->flows->save($data['flows']));
    }
}

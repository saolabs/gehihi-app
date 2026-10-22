<?php

namespace App\Modules\Workspace\Http;

use App\Http\Controllers\WebController;

class ShellController extends WebController
{
    protected $module = 'chat';

    public function index()
    {
        return $this->response([], '@module.index');
    }
}

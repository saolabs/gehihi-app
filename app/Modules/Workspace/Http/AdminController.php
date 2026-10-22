<?php

namespace App\Modules\Workspace\Http;

use App\Http\Controllers\WebController;

class AdminController extends WebController
{
    protected $context = 'admin';

    protected $module = 'settings';

    public function index()
    {
        abort_unless(request()->user()?->is_admin, 403);

        return $this->response([], '@module.index');
    }
}

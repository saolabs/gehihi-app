<?php

namespace App\Modules\Dashboard\Http\Controllers\Admin;

use App\Http\Controllers\AdminController;
use App\Modules\Dashboard\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends AdminController
{
    public function __construct(private DashboardService $dashboard) {}

    public function index(Request $r)
    {
        $days = in_array($r->integer('days'), [7, 30, 90], true) ? $r->integer('days') : 30;

        return $this->response($this->dashboard->overview($days));
    }
}

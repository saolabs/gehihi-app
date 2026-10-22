<?php

namespace App\Modules\Dashboard;

use App\Modules\Dashboard\Http\Controllers\Admin\DashboardController;
use Saola\Core\Providers\ModuleServiceProvider as CoreModuleServiceProvider;
use Saola\Core\System;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    public function routes(): void
    {
        System::context('admin')->module(['slug' => 'dashboard', 'prefix' => '/', 'priority' => 1])->controller(DashboardController::class)->as('dashboard')
            ->title('Tổng quan')->displayName('Tổng quan')->group(function ($m) {
                $m->get('/', 'index')->name('index');
            });
    }
}

<?php

namespace App\Modules\AiRuntime;

use App\Modules\AiRuntime\Http\Controllers\Admin\LimitController as AdminLimitController;
use App\Modules\AiRuntime\Http\Controllers\Api\RunController as ApiRunController;
use App\Modules\AiRuntime\Repositories\RunRepository;
use App\Modules\AiRuntime\Repositories\RunRepositoryInterface;
use App\Modules\AiRuntime\Services\Limits;
use Saola\Core\Providers\ModuleServiceProvider as CoreModuleServiceProvider;
use Saola\Core\System;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RunRepositoryInterface::class, RunRepository::class);
    }

    public function boot(): void
    {
        parent::boot();
        Limits::apply();
    }

    public function routes(): void
    {
        System::context('admin')->module(['slug' => 'limits', 'prefix' => 'limits'])->controller(AdminLimitController::class)->as('limits')
            ->title('Hạn mức')->displayName('Hạn mức')->group(function ($m) {
                $m->get('/', 'index')->name('index');
                $m->put('/', 'update')->name('update');
            });

        System::context('api')->module(['slug' => 'runs', 'prefix' => '/'])->controller(ApiRunController::class)->as('runs')->group(function ($m) {
            $m->post('/conversations/{id}/runs', 'start')->name('start')->middleware('throttle:20,1');
            $m->get('/conversations/{id}/run', 'latest')->name('latest');
            $m->get('/runs/{id}', 'state')->name('state');
            $m->get('/runs/{id}/events', 'events')->name('events');
            $m->post('/runs/{id}/cancel', 'cancel')->name('cancel');
            $m->post('/runs/{id}/resume', 'resume')->name('resume')->middleware('throttle:10,1');
            $m->get('/usage', 'usage')->name('usage');
        });
    }
}

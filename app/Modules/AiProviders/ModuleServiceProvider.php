<?php

namespace App\Modules\AiProviders;

use App\Modules\AiProviders\Http\Controllers\Admin\ProviderController as AdminProviderController;
use App\Modules\AiProviders\Http\Controllers\Admin\SearchServiceController;
use App\Modules\AiProviders\Http\Controllers\Api\ProviderController as ApiProviderController;
use App\Modules\AiProviders\Repositories\ProviderRepository;
use App\Modules\AiProviders\Repositories\ProviderRepositoryInterface;
use Saola\Core\Providers\ModuleServiceProvider as CoreModuleServiceProvider;
use Saola\Core\System;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRepositoryInterface::class, ProviderRepository::class);
    }

    public function routes(): void
    {
        System::context('admin')->module(['slug' => 'search-services', 'prefix' => 'search-services'])->controller(SearchServiceController::class)->as('search-services')
            ->title('Dịch vụ tìm kiếm')->displayName('Dịch vụ tìm kiếm')->group(function ($m) {
                $m->get('/', 'index')->name('index');
                $m->get('/{id}', 'edit')->name('edit');
                $m->post('/', 'store')->name('store');
                $m->post('/test', 'test')->name('test');
                $m->put('/{id}', 'update')->name('update');
                $m->delete('/{id}', 'destroy')->name('destroy');
            });

        System::context('admin')->module(['slug' => 'providers', 'prefix' => 'providers'])->controller(AdminProviderController::class)->as('providers')
            ->title('Provider')->displayName('Provider')->group(function ($m) {
                $m->get('/', 'index')->name('index');
                $m->get('/create', 'create')->name('create')->viewModule('form');
                $m->get('/{id}', 'edit')->name('edit')->viewModule('form');
                $m->post('/', 'store')->name('store');
                $m->post('/test', 'test')->name('test');
                $m->post('/discover', 'discover')->name('discover');
                $m->put('/{id}', 'update')->name('update');
                $m->delete('/{id}', 'destroy')->name('destroy');
            });

        System::context('api')->module(['slug' => 'providers', 'prefix' => 'providers'])->controller(ApiProviderController::class)->as('providers')->group(function ($m) {
            $m->get('/', 'index')->name('index')->middleware(['auth', 'throttle:30,1']);
            $m->post('/', 'save')->name('store')->middleware(['auth', 'throttle:30,1']);
            $m->post('/discover', 'models')->name('discover')->middleware(['auth', 'throttle:30,1']);
            $m->post('/test', 'test')->name('test')->middleware(['auth', 'throttle:30,1']);
            $m->patch('/{id}', 'save')->name('update')->middleware(['auth', 'throttle:30,1']);
            $m->delete('/{id}', 'delete')->name('destroy')->middleware(['auth', 'throttle:30,1']);
        });
    }
}

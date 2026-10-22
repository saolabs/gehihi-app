<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Saola\Core\System;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        System::context('web', ['as' => 'web', 'slug' => 'web', 'prefix' => '/', 'middleware' => ['web']]);
        System::context('admin', ['as' => 'admin', 'slug' => 'admin', 'prefix' => 'admin', 'middleware' => ['web', 'auth']]);
        $this->app->register(\App\Modules\Workspace\ModuleServiceProvider::class);
    }
}

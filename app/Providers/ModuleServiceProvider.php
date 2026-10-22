<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Saola\Core\System;

class ModuleServiceProvider extends ServiceProvider
{
    /** Thư mục nội bộ của module, không phải module con. */
    protected const INTERNAL_DIRS = ['Http', 'Models', 'Repositories', 'Services', 'Masks', 'Commands', 'Jobs', 'Events', 'Listeners', 'Notifications', 'Policies', 'Rules', 'Observers', 'Resources', 'Requests', 'Providers', 'Controllers', 'Middleware', 'Views'];

    public function register(): void
    {
        System::context('web', ['as' => 'web', 'slug' => 'web', 'prefix' => '/', 'middleware' => ['web']]);
        System::context('admin', ['as' => 'admin', 'slug' => 'admin', 'prefix' => 'admin', 'middleware' => ['web', 'auth', 'can:admin']]);
        // API phục vụ SPA cùng origin: dùng session + CSRF của nhóm web, workspace khách gắn qua WorkspaceSession.
        System::context('api', ['as' => 'api', 'slug' => 'api', 'prefix' => 'api', 'middleware' => ['web']]);

        $this->registerModules(app_path('Modules'), 'App\\Modules');
    }

    /** Mọi thư mục dưới app/Modules có ModuleServiceProvider đều được nạp, đệ quy cho module lồng nhau. */
    protected function registerModules(string $path, string $namespace): void
    {
        foreach (glob($path.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (in_array($name, static::INTERNAL_DIRS, true)) {
                continue;
            }
            if (class_exists($provider = $namespace.'\\'.$name.'\\ModuleServiceProvider')) {
                $this->app->register($provider);
            }
            $this->registerModules($dir, $namespace.'\\'.$name);
        }
    }
}

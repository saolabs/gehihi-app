<?php

namespace App\Modules\Workspace;

use App\Modules\Workspace\Http\AdminController;
use App\Modules\Workspace\Http\ShellController;
use Saola\Core\System;

class ModuleServiceProvider extends \Saola\Core\Providers\ModuleServiceProvider
{
    public function routes(): void
    {
        System::context('web')->module(['slug' => 'chat', 'prefix' => '/'])->controller(ShellController::class)->as('chat')->group(function ($m) {
            foreach (['/', '/c/{id}', '/p/{project}'] as $path) {
                $m->get($path, 'index')->name($path === '/' ? 'index' : ($path[1] === 'c' ? 'conversation' : 'project'))->viewModule('index');
            }
        });
        System::context('admin')->module(['slug' => 'settings', 'prefix' => '/'])->controller(AdminController::class)->as('settings')->group(function ($m) {
            foreach (['/' => 'index', '/providers' => 'providers', '/users' => 'users', '/limits' => 'limits'] as $path => $name) {
                $m->get($path, 'index')->name($name)->viewModule('index');
            }
        });
    }
}

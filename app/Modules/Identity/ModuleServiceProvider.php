<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Http\Controllers\Admin\UserController as AdminUserController;
use App\Modules\Identity\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Modules\Identity\Repositories\IdentityRepository;
use App\Modules\Identity\Repositories\IdentityRepositoryInterface;
use Illuminate\Http\Request;
use Saola\Core\Providers\ModuleServiceProvider as CoreModuleServiceProvider;
use Saola\Core\Saola;
use Saola\Core\System;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IdentityRepositoryInterface::class, IdentityRepository::class);
    }

    public function boot(): void
    {
        parent::boot();
        // Layout admin đọc qua App.shared('admin'): người đang đăng nhập và giao diện sáng/tối đã chọn.
        Saola::share('admin', fn (Request $r) => $r->user()?->is_admin ? [
            'name' => $r->user()->name, 'email' => $r->user()->email, 'initial' => mb_strtoupper(mb_substr($r->user()->name, 0, 1)),
            'theme' => $r->attributes->get('workspace')?->preferences['theme'] ?? 'system',
        ] : null);
    }

    public function routes(): void
    {
        System::context('admin')->module(['slug' => 'users', 'prefix' => 'users'])->controller(AdminUserController::class)->as('users')
            ->title('Người dùng')->displayName('Người dùng')->group(function ($m) {
                $m->get('/', 'index')->name('index');
                $m->put('/{id}', 'update')->name('update');
            });

        System::context('api')->module(['slug' => 'auth', 'prefix' => 'auth'])->controller(ApiAuthController::class)->as('auth')->group(function ($m) {
            foreach (['login', 'register', 'forgot', 'reset'] as $action) {
                $m->post('/'.$action, $action)->name($action)->middleware('throttle:10,1');
            }
            $m->post('/logout', 'logout')->name('logout');
            $m->post('/import', 'import')->name('import')->middleware('auth');
        });
    }
}

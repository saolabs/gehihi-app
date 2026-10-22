<?php

namespace App\Providers;

use App\Modules\AiProviders\Repositories\ProviderRepository;
use App\Modules\AiRuntime\Repositories\RunRepository;
use App\Modules\Identity\Repositories\IdentityRepository;
use App\Modules\Workspace\Repositories\WorkspaceRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([WorkspaceRepository::class, ProviderRepository::class, RunRepository::class, IdentityRepository::class] as $class) {
            $this->app->singleton($class.'Interface', $class);
        }
    }
}

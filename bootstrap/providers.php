<?php

use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;
use Saola\Core\Providers\BladeDirectiveServiceProvider;
use Saola\Core\Providers\SaolaServiceProvider;

return [AppServiceProvider::class, ModuleServiceProvider::class, BladeDirectiveServiceProvider::class, SaolaServiceProvider::class];

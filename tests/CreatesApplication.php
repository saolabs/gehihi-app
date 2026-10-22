<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Saola\Core\System;

trait CreatesApplication
{
    public function createApplication(): Application
    {
        // System keeps the route tree in statics; each test app would otherwise re-add every action.
        System::reset();
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}

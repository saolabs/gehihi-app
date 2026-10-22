<?php

use Saola\Core\Support\SPA;
use Saola\Core\System;

// Route khai báo trong ModuleServiceProvider::routes() của từng module; ở đây chỉ đẩy ra Laravel theo context.
SPA::active();
SPA::resetRoutes();
System::context('admin')?->pushLaravelRoute();
System::context('web')?->pushLaravelRoute();
SPA::inactive();
System::context('api')?->pushLaravelRoute();

<?php

namespace App\Http\Controllers;

use Saola\Core\Http\Controllers\BaseWebController;

abstract class WebController extends BaseWebController
{
    protected $context = 'web';

    protected $module = null;
}

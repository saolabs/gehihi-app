<?php

use Saola\Core\Support\SPA;
use Saola\Core\System;

SPA::active();
SPA::resetRoutes();
System::context('admin')?->pushLaravelRoute();
System::context('web')?->pushLaravelRoute();
SPA::inactive();

use App\Modules\AiProviders\Http\ProviderController as Providers;
use App\Modules\AiRuntime\Http\RunController as Runs;
use App\Modules\Identity\Http\IdentityController as Identity;
use App\Modules\Workspace\Http\WorkspaceController as Workspace;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('/bootstrap', [Workspace::class, 'bootstrap']);
    Route::middleware('throttle:10,1')->group(function () {
        foreach (['login', 'register', 'forgot', 'reset'] as $action) {
            Route::post('/auth/'.$action, [Identity::class, $action]);
        }
    });
    Route::post('/auth/logout', [Identity::class, 'logout']);
    Route::post('/auth/import', [Identity::class, 'import'])->middleware('auth');
    Route::post('/projects', [Workspace::class, 'project']);
    Route::patch('/projects/{id}', [Workspace::class, 'project']);
    Route::delete('/projects/{id}', [Workspace::class, 'deleteProject']);
    Route::post('/conversations', [Workspace::class, 'createConversation']);
    Route::get('/conversations/{id}', [Workspace::class, 'conversation']);
    Route::patch('/conversations/{id}', [Workspace::class, 'updateConversation']);
    Route::delete('/conversations/{id}', [Workspace::class, 'deleteConversation']);
    Route::get('/conversations/{id}/export', [Workspace::class, 'export']);
    Route::put('/preferences', [Workspace::class, 'preferences']);
    Route::post('/files', [Workspace::class, 'upload'])->middleware('throttle:12,1');
    Route::get('/files/{id}/download', [Workspace::class, 'download']);
    Route::delete('/files/{id}', [Workspace::class, 'deleteFile']);
    Route::middleware(['auth', 'throttle:30,1'])->group(function () {
        Route::get('/providers', [Providers::class, 'index']);
        Route::post('/providers', [Providers::class, 'save']);
        Route::patch('/providers/{id}', [Providers::class, 'save']);
        Route::delete('/providers/{id}', [Providers::class, 'delete']);
        Route::post('/providers/discover', [Providers::class, 'models']);
        Route::post('/providers/test', [Providers::class, 'test']);
    });
    Route::post('/conversations/{id}/runs', [Runs::class, 'start'])->middleware('throttle:20,1');
    Route::get('/conversations/{id}/run', [Runs::class, 'latest']);
    Route::get('/runs/{id}', [Runs::class, 'state']);
    Route::get('/runs/{id}/events', [Runs::class, 'events']);
    Route::post('/runs/{id}/cancel', [Runs::class, 'cancel']);
    Route::post('/runs/{id}/resume', [Runs::class, 'resume'])->middleware('throttle:10,1');
    Route::get('/usage',[Runs::class, 'usage']);
});

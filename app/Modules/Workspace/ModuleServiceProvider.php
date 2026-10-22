<?php

namespace App\Modules\Workspace;

use App\Modules\Workspace\Http\Controllers\Api\WorkspaceController as ApiWorkspaceController;
use App\Modules\Workspace\Http\Controllers\Web\ShellController;
use App\Modules\Workspace\Repositories\WorkspaceRepository;
use App\Modules\Workspace\Repositories\WorkspaceRepositoryInterface;
use Saola\Core\Providers\ModuleServiceProvider as CoreModuleServiceProvider;
use Saola\Core\System;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkspaceRepositoryInterface::class, WorkspaceRepository::class);
    }

    public function routes(): void
    {
        System::context('web')->module(['slug' => 'chat', 'prefix' => '/'])->controller(ShellController::class)->as('chat')->group(function ($m) {
            foreach (['/', '/c/{id}', '/p/{project}'] as $path) {
                $m->get($path, 'index')->name($path === '/' ? 'index' : ($path[1] === 'c' ? 'conversation' : 'project'))->viewModule('index');
            }
        });

        System::context('api')->module(['slug' => 'workspace', 'prefix' => '/'])->controller(ApiWorkspaceController::class)->as('workspace')->group(function ($m) {
            $m->get('/bootstrap', 'bootstrap')->name('bootstrap');
            $m->put('/preferences', 'preferences')->name('preferences');
            $m->post('/projects', 'project')->name('projects.store');
            $m->patch('/projects/{id}', 'project')->name('projects.update');
            $m->delete('/projects/{id}', 'deleteProject')->name('projects.destroy');
            $m->post('/conversations', 'createConversation')->name('conversations.store');
            $m->get('/conversations/{id}', 'conversation')->name('conversations.show');
            $m->patch('/conversations/{id}', 'updateConversation')->name('conversations.update');
            $m->delete('/conversations/{id}', 'deleteConversation')->name('conversations.destroy');
            $m->get('/conversations/{id}/export', 'export')->name('conversations.export');
            $m->post('/files', 'upload')->name('files.store')->middleware('throttle:12,1');
            $m->get('/files/{id}/download', 'download')->name('files.download');
            $m->delete('/files/{id}', 'deleteFile')->name('files.destroy');
        });
    }
}

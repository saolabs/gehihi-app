<?php

namespace App\Modules\Workspace\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Modules\Workspace\Services\FileService;
use App\Modules\Workspace\Services\WorkspaceService;
use Illuminate\Http\Request;

class WorkspaceController extends ApiController
{
    public function __construct(private WorkspaceService $service, private FileService $files) {}

    public function bootstrap(Request $r)
    {
        return $this->ok($this->service->bootstrap($r));
    }

    public function project(Request $r, ?string $id = null)
    {
        $data = $r->validate(['name' => [$id ? 'sometimes' : 'required', 'string', 'max:120'], 'instructions' => ['sometimes', 'nullable', 'string', 'max:14000'], 'archived' => ['sometimes', 'boolean'], ...$this->selectionRules()]);

        return $this->ok($this->service->project($this->ws($r), $id, $data));
    }

    public function deleteProject(Request $r, string $id)
    {
        $this->service->deleteProject($this->ws($r), $id);

        return $this->ok();
    }

    public function createConversation(Request $r)
    {
        $data = $r->validate(['project_id' => ['nullable', 'uuid'], 'temporary' => ['sometimes', 'boolean']]);

        return $this->ok($this->service->createConversation($this->ws($r), $data), 201);
    }

    public function conversation(Request $r, string $id)
    {
        return $this->ok($this->service->detail($this->ws($r), $id));
    }

    public function updateConversation(Request $r, string $id)
    {
        $data = $r->validate(['title' => ['sometimes', 'string', 'max:180'], 'project_id' => ['sometimes', 'nullable', 'uuid'], 'pinned' => ['sometimes', 'boolean'], 'archived' => ['sometimes', 'boolean'], 'selected_leaf_id' => ['sometimes', 'nullable', 'uuid'], ...$this->selectionRules()]);

        return $this->ok($this->service->updateConversation($this->ws($r), $id, $data));
    }

    public function deleteConversation(Request $r, string $id)
    {
        $this->service->deleteConversation($this->ws($r), $id);

        return $this->ok();
    }

    public function export(Request $r, string $id)
    {
        $format = $r->query('format') === 'json' ? 'json' : 'md';

        return response($this->service->export($this->ws($r), $id, $format))->header('Content-Type', $format === 'json' ? 'application/json' : 'text/markdown; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="gehihi.'.$format.'"');
    }

    public function preferences(Request $r)
    {
        $data = $r->validate(['theme' => ['sometimes', 'in:light,dark,system'], 'locale' => ['sometimes', 'in:vi,en'], ...$this->selectionRules()]);
        $this->service->preferences($this->ws($r), $data);

        return $this->ok();
    }

    public function upload(Request $r)
    {
        $r->validate(['file' => ['required', 'file', 'max:10240'], 'project_id' => ['nullable', 'uuid']]);

        return $this->ok($this->files->upload($this->ws($r), $r->file('file'), $r->input('project_id')), 201);
    }

    public function download(Request $r, string $id)
    {
        $file = $this->files->download($this->ws($r), $id);

        return response()->download($file['path'], $file['name'], ['Content-Type' => 'application/octet-stream']);
    }

    public function deleteFile(Request $r, string $id)
    {
        $this->files->delete($this->ws($r),$id);

        return $this->ok();
    }

    public function trash(Request $r)
    {
        return $this->ok($this->service->trash($this->ws($r)));
    }

    public function restore(Request $r, string $type, string $id)
    {
        $this->service->restore($this->ws($r), $type, $id);

        return $this->ok($this->service->trash($this->ws($r)));
    }

    public function forget(Request $r, string $type, string $id)
    {
        $this->files->forget($this->ws($r), $type, $id);

        return $this->ok($this->service->trash($this->ws($r)));
    }
}

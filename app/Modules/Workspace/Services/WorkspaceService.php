<?php

namespace App\Modules\Workspace\Services;

use App\Modules\AiProviders\Services\ProviderService;
use App\Modules\Workspace\Repositories\WorkspaceRepositoryInterface;
use Illuminate\Http\Request;

class WorkspaceService
{
    public function __construct(private WorkspaceRepositoryInterface $repo, private ProviderService $providers) {}

    public function bootstrap(Request $request): array
    {
        $ws = $request->attributes->get('workspace');

        return ['workspace' => ['id' => $ws->id, 'guest' => ! $request->user(), 'expires_at' => $ws->expires_at, 'preferences' => $ws->preferences ?? []], 'user' => $request->user(), 'projects' => $this->repo->projects($ws->id), 'conversations' => $this->repo->conversations($ws->id, (string) $request->query('q', '')), 'files' => $this->repo->files($ws->id), 'catalog' => $this->providers->catalog($request->user()?->id), 'import_available' => $request->user() && $request->session()->has('guest_workspace_id'), 'limits' => ['daily_runs' => config('gehihi.daily_runs'), 'upload_mb' => config('gehihi.upload_bytes') / 1048576, 'guest_days' => config('gehihi.guest_days')]];
    }

    public function project(string $ws, ?string $id, array $data): array
    {
        if (array_key_exists('instructions', $data)) {
            $data['instructions'] = $data['instructions'] ?? '';
        }
        if (isset($data['selection'])) {
            $this->providers->assertSelection($data['selection'], request()->user()?->id);
        }

        return $this->repo->saveProject($ws, $id, $data)->toArray();
    }

    public function deleteProject(string $ws, string $id): void
    {
        $this->repo->deleteProject($ws, $id);
    }

    public function createConversation(string $ws, array $data): array
    {
        if (! empty($data['project_id'])) {
            $this->repo->project($ws, $data['project_id']);
        }

return $this->repo->createConversation($ws, $data)->toArray();
    }

    public function detail(string $ws, string $id): array
    {
        $c = $this->repo->conversation($ws, $id);

        return [...$c->toArray(), 'messages' => $this->repo->messages($id), 'project' => $c->project_id ? $this->repo->project($ws, $c->project_id)->toArray() : null];
    }

    public function updateConversation(string $ws, string $id, array $data): array
    {
        $c = $this->repo->conversation($ws, $id);
        if (array_key_exists('project_id', $data) && $c->project_id !== $data['project_id']) {
            if ($data['project_id']) {
                $this->repo->project($ws, $data['project_id']);
            }
            // A marker is retained separately from message ancestry. New runs always rebuild context.
            $data['selection'] = null;
        }
        if (! empty($data['selected_leaf_id'])) {
            $this->repo->message($id, $data['selected_leaf_id']);
        }
        if (isset($data['selection'])) {
            $this->providers->assertSelection($data['selection'], request()->user()?->id);
        }

        return $this->repo->updateConversation($ws, $id, $data)->toArray();
    }

    public function deleteConversation(string $ws, string $id): void
    {
        $this->repo->deleteConversation($ws, $id);
    }

    public function preferences(string $ws, array $data): void
    {
        if (! empty($data['selection'])) {
            $this->providers->assertSelection($data['selection'], request()->user()?->id);
        }
        $this->repo->preferences($ws, $data);
    }

    public function export(string $ws, string $id, string $format): string
    {
        $c = $this->detail($ws, $id);
        if ($format === 'json') {
            return json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }

        return '# '.$c['title']."\n\n".implode("\n\n", array_map(fn ($m) => '## '.($m['role'] === 'user' ? 'Bạn' : 'Gehihi')."\n\n".$m['content'], $c['messages']));
    }
}

<?php

namespace App\Modules\AiRuntime\Services;

use App\Modules\AiProviders\Services\ProviderService;
use App\Modules\AiRuntime\Jobs\ExecuteRun;
use App\Modules\AiRuntime\Repositories\RunRepositoryInterface;
use App\Modules\Workspace\Repositories\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RunService
{
    public function __construct(private WorkspaceRepositoryInterface $workspace, private RunRepositoryInterface $runs, private ContextBuilder $context, private ProviderService $providers, private ModelFlows $flows) {}

    public function start(string $ws, string $conversation, ?string $userId, array $data): array
    {
        $run = Cache::lock('workspace-run-'.$ws, 10)->block(5, fn () => DB::transaction(function () use ($ws, $conversation, $userId, $data) {
            $c = $this->workspace->conversation($ws, $conversation);
            $old = $this->runs->existing($ws, $data['request_id']);
            if ($old) {
                abort_unless($old->conversation_id === $conversation, 409, 'Mã gửi đã dùng cho cuộc trò chuyện khác.');

                return $old;
            }
            if (array_key_exists('selection', $data)) {
                $c = $this->workspace->updateConversation($ws, $conversation, ['selection' => $data['selection']]);
            }
            $this->runs->reserve($ws);
            $parent = array_key_exists('parent_id', $data) ? $data['parent_id'] : $c->selected_leaf_id;
            if ($parent) {
                $this->workspace->message($conversation, $parent);
            }
            if (! empty($data['regenerate_id'])) {
                $m = $this->workspace->message($conversation, $data['regenerate_id']);
                abort_unless($m->role === 'assistant' && $m->parent_id, 422);
                $parent = $m->parent_id;
                $user = $this->workspace->message($conversation, $parent);
                $fileIds = $user->metadata['file_ids'] ?? [];
            } else {
                $fileIds = $data['file_ids'] ?? [];
                foreach ($fileIds as $id) {
                    $f = $this->workspace->file($ws, $id);
                    abort_if($f->project_id && $f->project_id !== $c->project_id, 422, 'Tệp thuộc dự án khác.');
                }
                $user = $this->workspace->addMessage($conversation, ['role' => 'user', 'parent_id' => $parent, 'content' => $data['message'], 'metadata' => ['file_ids' => $fileIds]]);
                $parent = $user->id;
            }
            $snapshot = $this->context->snapshot($ws, $conversation, $parent, $fileIds, $userId, $data);
            $candidates = $this->providers->resolve($snapshot['selection'], $userId, $snapshot['vision'], $data['thinking'] ?? 'auto');
            $snapshot['candidates'] = $candidates;
            if (($data['mode'] ?? 'chat') !== 'chat') {
                $snapshot['stage_candidates'] = $this->flows->snapshot($ws, $userId, $candidates, $data['thinking'] ?? 'auto');
            }
            $message = $this->workspace->addMessage($conversation, ['role' => 'assistant', 'parent_id' => $parent, 'content' => '', 'status' => 'queued']);
            $this->workspace->updateConversation($ws, $conversation, ['selected_leaf_id' => $message->id, 'title' => $c->title === 'Cuộc trò chuyện mới' ? mb_substr($user->content, 0, 65) : $c->title]);

            return $this->runs->create(['workspace_id' => $ws, 'conversation_id' => $conversation, 'message_id' => $message->id, 'request_id' => $data['request_id'], 'mode' => $data['mode'] ?? 'chat', 'snapshot' => $snapshot, 'reserved_tokens' => config(($data['mode'] ?? 'chat') === 'chat' ? 'gehihi.chat_tokens' : 'gehihi.research_tokens')]);
        }));
        if ($run->status === 'queued') {
            ExecuteRun::dispatch($run->id);
        }

        return $this->state($ws, $run->id);
    }

    public function state(string $ws, string $id): array
    {
        $r = $this->runs->owned($ws, $id);
        $this->workspace->conversation($ws, $r->conversation_id);
        $policy = config('gehihi.research.'.$r->mode, []);
        $checkpoint = $r->checkpoint ?? [];
        $canResume = $r->mode !== 'chat' && in_array($r->status, ['cancelled', 'failed', 'partial']) && $r->used_tokens < $r->reserved_tokens;
        if ($r->status === 'partial') {
            $canResume = $canResume && ! ($checkpoint['coverage']['complete'] ?? false)
                && ($checkpoint['round'] ?? 0) < ($policy['rounds'] ?? 0)
                && count($checkpoint['queries'] ?? []) < ($policy['queries'] ?? 0)
                && count($checkpoint['pending_queries'] ?? []) > 0;
        }

        return [...collect($r->toArray())->except(['snapshot', 'checkpoint'])->all(), 'content' => $this->workspace->message($r->conversation_id, $r->message_id)->content, 'context_truncated' => $r->snapshot['context_truncated'] ?? false, 'can_resume' => $canResume, 'operations' => $this->runs->operations($r->id)];
    }

    public function latest(string $ws, string $conversation): ?array
    {
        $c = $this->workspace->conversation($ws, $conversation);
        if (! $c->selected_leaf_id) {
            return null;
        }$r = $this->runs->latest($ws, $conversation, $c->selected_leaf_id);

        return $r ? $this->state($ws, $r->id) : null;
    }

    public function cancel(string $ws, string $id): array
    {
        $this->state($ws, $id);
        $r = $this->runs->owned($ws, $id);
        if (in_array($r->status, ['queued', 'running'])) {
            $this->runs->finish($id, 'cancelled', null, 'Người dùng yêu cầu dừng.');
        }

        return $this->state($ws, $id);
    }

    public function resume(string $ws, string $id): array
    {
        $this->state($ws, $id);
        $run = Cache::lock('workspace-run-'.$ws, 10)->block(5, fn () => DB::transaction(function () use ($ws, $id) {
            $this->runs->reserve($ws);

            return $this->runs->resume($ws, $id);
        }));
        ExecuteRun::dispatch($id, $run->checkpoint['epoch'] ?? 0);

        return $this->state($ws, $id);
    }

    public function usage(string $ws): array
    {
        return $this->runs->usage($ws);
    }
}

<?php

namespace App\Modules\AiRuntime\Repositories;

use App\Modules\AiRuntime\Models\GenerationRun;
use App\Modules\AiRuntime\Services\ResearchLimit;
use App\Modules\AiRuntime\Services\RunStopped;
use App\Modules\Workspace\Models\Conversation;
use App\Modules\Workspace\Models\Message;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;

class RunRepository implements RunRepositoryInterface
{
    private array $epochs = [];

    private function current(GenerationRun $run): bool
    {
        return ! array_key_exists($run->id, $this->epochs) || $this->epochs[$run->id] === ($run->checkpoint['epoch'] ?? 0);
    }

    public function find(string $id): GenerationRun
    {
        return GenerationRun::findOrFail($id);
    }

    public function owned(string $ws, string $id): GenerationRun
    {
        return GenerationRun::where('workspace_id', $ws)->findOrFail($id);
    }

    public function existing(string $ws, string $requestId): ?GenerationRun
    {
        return GenerationRun::where('workspace_id', $ws)->where('request_id', $requestId)->first();
    }

    public function latest(string $ws, string $conversation, ?string $message = null): ?GenerationRun
    {
        return GenerationRun::where('workspace_id', $ws)->where('conversation_id', $conversation)->when($message, fn ($query) => $query->where('message_id', $message))->latest()->first();
    }

    public function reserve(string $ws): void
    {
        Workspace::whereKey($ws)->lockForUpdate()->firstOrFail();
        $runs = GenerationRun::where('workspace_id', $ws)->where('created_at', '>=', now()->startOfDay());
        abort_if((clone $runs)->count() >= config('gehihi.daily_runs'), 429, 'Đã đạt số lượt sử dụng hôm nay.');
        abort_if(GenerationRun::where('workspace_id', $ws)->whereIn('status', ['queued', 'running'])->count() >= config('gehihi.concurrency'), 429, 'Đang có quá nhiều lượt chạy. Hãy đợi hoặc dừng lượt hiện tại.');
    }

    public function create(array $data): GenerationRun
    {
        $used = GenerationRun::where('workspace_id', $data['workspace_id'])->where('created_at', '>=', now()->startOfDay())->get()->sum(fn ($r) => in_array($r->status, ['queued', 'running']) ? $r->reserved_tokens : $r->used_tokens);
        abort_if($used + $data['reserved_tokens'] > config('gehihi.daily_tokens'), 429, 'Không đủ hạn mức token hôm nay cho lượt chạy này.');

        return GenerationRun::create($data);
    }

    public function claim(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $run = GenerationRun::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'queued') {
                return false;
            }$this->epochs[$id] = $run->checkpoint['epoch'] ?? 0;
            $run->update(['status' => 'running', 'started_at' => now(), 'progress' => 'Đang chuẩn bị ngữ cảnh']);

            return true;
        });
    }

    public function update(string $id, array $data): void
    {
        DB::transaction(function () use ($id, $data) {
            $run = GenerationRun::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $this->current($run) || $run->status !== 'running') {
                throw new RunStopped;
            }$run->update($data);
        });
    }

    public function active(string $id): bool
    {
        $r = $this->find($id);

        return $this->current($r) && $r->status === 'running' && Conversation::whereKey($r->conversation_id)->exists() && Workspace::whereKey($r->workspace_id)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }

    public function content(string $runId, string $text): void
    {
        DB::transaction(function () use ($runId, $text) {
            $r = GenerationRun::whereKey($runId)->lockForUpdate()->firstOrFail();
            if (! $this->current($r) || $r->status !== 'running') {
                throw new RunStopped;
            }
            Message::whereKey($r->message_id)->update(['content' => $text, 'status' => 'streaming']);
        });
    }

    public function finish(string $id, string $status, ?string $error = null, ?string $reason = null, ?int $epoch = null): void
    {
        DB::transaction(function () use ($id, $status, $error, $reason, $epoch) {
            $r = GenerationRun::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $this->current($r) || ($epoch !== null && $epoch !== ($r->checkpoint['epoch'] ?? 0)) || in_array($r->status, ['cancelled', 'completed', 'partial', 'failed'])) {
                return;
            }
            $r->update(['status' => $status, 'error' => $error, 'stop_reason' => $reason, 'finished_at' => now(), 'progress' => match ($status) {
                'completed' => 'Đã hoàn tất','cancelled' => 'Đã dừng','partial' => 'Kết quả một phần',default => 'Chưa hoàn tất'
            }]);
            Message::whereKey($r->message_id)->update(['status' => $status, 'metadata' => ['run_id' => $id, 'sources' => $r->sources ?? [], 'model' => $r->actual_model, 'instruction_revision' => $r->snapshot['instruction_revision'] ?? null, 'project_id' => $r->snapshot['project_id'] ?? null, 'error' => $error, 'stop_reason' => $reason]]);
        });
    }

    public function resume(string $ws, string $id): GenerationRun
    {
        $r = GenerationRun::where('workspace_id', $ws)->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless($r->mode !== 'chat' && in_array($r->status, ['cancelled', 'failed', 'partial']), 422, 'Lượt này không thể tiếp tục.');
        abort_if($r->used_tokens >= $r->reserved_tokens, 429, 'Lượt này đã hết ngân sách. Hãy tạo nghiên cứu mới.');
        $daily = GenerationRun::where('workspace_id', $ws)->where('id', '!=', $id)->where('created_at', '>=', now()->startOfDay())->get()->sum(fn ($run) => in_array($run->status, ['queued', 'running']) ? $run->reserved_tokens : $run->used_tokens);
        abort_if($daily + $r->reserved_tokens > config('gehihi.daily_tokens'), 429, 'Không đủ hạn mức hôm nay để tiếp tục nghiên cứu.');
        $r->update(['checkpoint' => [...($r->checkpoint ?? []), 'epoch' => (($r->checkpoint['epoch'] ?? 0) + 1)], 'status' => 'queued', 'error' => null, 'finished_at' => null, 'stop_reason' => null, 'progress' => 'Đang chờ tiếp tục']);

        return $r;
    }

    public function operation(string $id, string $key): ?array
    {
        $row = DB::table('run_operations')->where('run_id', $id)->where('operation_key', $key)->first();

        return $row ? json_decode($row->result ?? 'null', true) : null;
    }

    public function beginOperation(string $id, string $key, string $kind, int $reservation): void
    {
        DB::transaction(function () use ($id, $key, $kind, $reservation) {
            $r = GenerationRun::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $this->current($r) || $r->status !== 'running') {
                throw new RunStopped;
            }
            if ($r->used_tokens + $reservation > $r->reserved_tokens) {
                throw new ResearchLimit('Đã hết ngân sách token của lượt nghiên cứu.');
            }
            $exists = DB::table('run_operations')->where('run_id', $id)->where('operation_key', $key)->first();
            if ($exists) {
                throw new \RuntimeException('Một thao tác chưa được đối soát; hãy tạo lượt mới để tránh gọi trùng.');
            }
            DB::table('run_operations')->insert(['run_id' => $id, 'operation_key' => $key, 'kind' => $kind, 'tokens' => $reservation, 'metering' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
            $r->increment('used_tokens', $reservation);
        });
    }

    public function completeOperation(string $id, string $key, array $result, int $tokens, string $metering): void
    {
        DB::transaction(function () use ($id, $key, $result, $tokens, $metering) {
            $row = DB::table('run_operations')->where('run_id', $id)->where('operation_key', $key)->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            GenerationRun::whereKey($id)->increment('used_tokens', $tokens - $row->tokens);
            DB::table('run_operations')->where('id', $row->id)->update(['result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'tokens' => $tokens, 'metering' => $metering, 'updated_at' => now()]);
        });
    }

    public function operations(string $id): array
    {
        return DB::table('run_operations')->where('run_id', $id)->get(['kind', 'tokens', 'metering', 'created_at'])->toArray();
    }

    public function usage(string $ws): array
    {
        return ['runs_today' => GenerationRun::where('workspace_id',$ws)->whereDate('created_at',today())->count(), 'tokens_today' => (int) GenerationRun::where('workspace_id',$ws)->whereDate('created_at',today())->sum('used_tokens')];
    }
}

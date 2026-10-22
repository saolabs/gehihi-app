<?php

namespace App\Modules\Admin\Services;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiProviders\Repositories\ProviderRepositoryInterface;
use App\Modules\AiRuntime\Models\GenerationRun;
use App\Modules\Identity\Models\User;
use App\Modules\Workspace\Models\Conversation;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Collection;

class AdminStats
{
    public function __construct(private ProviderRepositoryInterface $providers) {}

    // ponytail: gom nhóm trong PHP để chạy được cả SQLite/MySQL; khi run 30 ngày lên hàng trăm nghìn thì chuyển sang GROUP BY theo ngày.
    private function runs(int $days): Collection
    {
        return GenerationRun::where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->get(['id', 'workspace_id', 'mode', 'status', 'used_tokens', 'actual_model', 'error', 'created_at']);
    }

    public function overview(int $days): array
    {
        $runs = $this->runs($days);
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $series[now()->subDays($i)->toDateString()] = ['date' => now()->subDays($i)->toDateString(), 'runs' => 0, 'failed' => 0, 'tokens' => 0];
        }
        foreach ($runs as $run) {
            $day = $run->created_at->toDateString();
            $series[$day]['runs']++;
            $series[$day]['tokens'] += $run->used_tokens;
            $series[$day]['failed'] += $run->status === 'failed' ? 1 : 0;
        }
        $models = $runs->filter(fn ($r) => $r->actual_model)->groupBy(fn ($r) => $r->actual_model['provider_name'].' · '.$r->actual_model['model_id'])
            ->map(fn ($group, $name) => ['name' => $name, 'runs' => $group->count(), 'tokens' => $group->sum('used_tokens')])->sortByDesc('runs')->take(6)->values();

        return [
            'days' => $days,
            'totals' => [
                'users' => User::count(),
                'admins' => User::where('is_admin', true)->count(),
                'guest_workspaces' => Workspace::whereNull('user_id')->where('expires_at', '>', now())->count(),
                'conversations' => Conversation::where('temporary', false)->count(),
                'runs' => $runs->count(),
                'failed' => $runs->where('status', 'failed')->count(),
                'tokens' => $runs->sum('used_tokens'),
                'active' => GenerationRun::whereIn('status', ['queued', 'running'])->count(),
                'runs_today' => $series[now()->toDateString()]['runs'],
                'tokens_today' => $series[now()->toDateString()]['tokens'],
            ],
            'modes' => collect(['chat', 'quick', 'deep'])->map(fn ($m) => ['mode' => $m, 'runs' => $runs->where('mode', $m)->count()])->values(),
            'series' => array_values($series),
            'models' => $models,
            'failures' => $runs->where('status', 'failed')->sortByDesc('created_at')->take(8)->map(fn ($r) => ['id' => $r->id, 'mode' => $r->mode, 'error' => $r->error, 'model' => $r->actual_model['model_id'] ?? null, 'created_at' => $r->created_at])->values(),
        ];
    }

    public function providers(): array
    {
        $runs = $this->runs(7)->filter(fn ($r) => $r->actual_model)->groupBy(fn ($r) => $r->actual_model['provider_id']);

        return [
            'system' => Provider::whereNull('user_id')->orderBy('kind')->orderBy('name')->get()->map(fn (Provider $p) => [
                'id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'type' => $p->type, 'enabled' => $p->enabled, 'is_default' => $p->is_default,
                'models' => count($p->models ?? []), 'default_model' => $p->default_model, 'key_count' => count($p->secrets ?? []), 'cooling_keys' => $this->providers->coolingKeys($p),
                'test_status' => $p->test_status, 'tested_at' => $p->tested_at,
                'runs_7d' => ($runs[$p->id] ?? collect())->count(), 'failed_7d' => ($runs[$p->id] ?? collect())->where('status', 'failed')->count(),
            ])->values(),
            'personal' => ['providers' => Provider::whereNotNull('user_id')->count(), 'users' => Provider::whereNotNull('user_id')->distinct()->count('user_id')],
        ];
    }

    public function users(string $q): array
    {
        $users = User::when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%")))
            ->orderByDesc('created_at')->limit(200)->get();
        $workspaces = Workspace::whereIn('user_id', $users->pluck('id'))->pluck('id', 'user_id');
        $runs = GenerationRun::whereIn('workspace_id', $workspaces->values())->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->get(['workspace_id', 'used_tokens', 'created_at'])->groupBy('workspace_id');
        $conversations = Conversation::whereIn('workspace_id', $workspaces->values())->where('temporary', false)
            ->selectRaw('workspace_id, count(*) as total')->groupBy('workspace_id')->pluck('total', 'workspace_id');

        return $users->map(function (User $u) use ($workspaces, $runs, $conversations) {
            $ws = $workspaces[$u->id] ?? null;
            $mine = $runs[$ws] ?? collect();

            return [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_admin' => $u->is_admin, 'created_at' => $u->created_at,
                'conversations' => (int) ($conversations[$ws] ?? 0), 'runs_30d' => $mine->count(), 'tokens_30d' => $mine->sum('used_tokens'),
                'runs_today' => $mine->filter(fn ($r) => $r->created_at->isToday())->count(),
            ];
        })->values()->all();
    }
}

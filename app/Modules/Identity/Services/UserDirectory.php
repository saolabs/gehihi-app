<?php

namespace App\Modules\Identity\Services;

use App\Modules\AiRuntime\Models\GenerationRun;
use App\Modules\Identity\Models\User;
use App\Modules\Workspace\Models\Conversation;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Format;

/** Danh sách tài khoản kèm mức sử dụng 30 ngày cho trang quản trị. */
class UserDirectory
{
    public function users(string $q, string $me): array
    {
        $users = User::when($q !== '', fn ($query) => $query->where(fn ($w) => $w->whereLike('name', "%$q%")->orWhereLike('email', "%$q%")))
            ->orderByDesc('created_at')->limit(200)->get();
        $workspaces = Workspace::whereIn('user_id', $users->pluck('id'))->pluck('id', 'user_id');
        $runs = GenerationRun::whereIn('workspace_id', $workspaces->values())->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->get(['workspace_id', 'used_tokens'])->groupBy('workspace_id');
        $conversations = Conversation::whereIn('workspace_id', $workspaces->values())->where('temporary', false)
            ->selectRaw('workspace_id, count(*) as total')->groupBy('workspace_id')->pluck('total', 'workspace_id');

        return $users->map(function (User $u) use ($workspaces, $runs, $conversations, $me) {
            $ws = $workspaces[$u->id] ?? null;
            $mine = $runs[$ws] ?? collect();

            return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'initial' => mb_strtoupper(mb_substr($u->name, 0, 1)), 'is_admin' => $u->is_admin, 'is_me' => $u->id === $me,
                'joined' => Format::when($u->created_at), 'conversations' => Format::num($conversations[$ws] ?? 0), 'runs' => Format::num($mine->count()), 'tokens' => Format::num($mine->sum('used_tokens'))];
        })->values()->all();
    }
}

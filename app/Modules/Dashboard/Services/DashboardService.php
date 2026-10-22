<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\AiRuntime\Models\GenerationRun;
use App\Modules\Identity\Models\User;
use App\Modules\Workspace\Models\Conversation;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Format;
use Illuminate\Support\Collection;

/** Số liệu tổng quan trang quản trị: lượt chạy, token, lỗi, model theo khoảng ngày. */
class DashboardService
{
    private const MODES = ['chat' => 'Trò chuyện', 'quick' => 'Tra cứu web', 'deep' => 'Nghiên cứu sâu'];

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
            $series[now()->subDays($i)->toDateString()] = ['runs' => 0, 'failed' => 0, 'tokens' => 0];
        }
        foreach ($runs as $run) {
            $day = $run->created_at->toDateString();
            $series[$day]['runs']++;
            $series[$day]['tokens'] += $run->used_tokens;
            $series[$day]['failed'] += $run->status === 'failed' ? 1 : 0;
        }
        $today = $series[now()->toDateString()];
        $failed = $runs->where('status', 'failed')->count();
        $peak = max(1, ...array_column($series, 'runs'));
        $step = $days > 31 ? 14 : ($days > 7 ? 5 : 1);
        $bars = [];
        foreach (array_keys($series) as $i => $date) {
            $d = $series[$date];
            $label = substr($date, 8, 2).'/'.substr($date, 5, 2);
            $bars[] = ['key' => $date, 'height' => $d['runs'] ? max(3, round($d['runs'] / $peak * 100, 1)) : 0, 'tick' => $i % $step === 0 || $i === $days - 1 ? $label : '',
                'tip' => $label.': '.Format::num($d['runs']).' lượt · '.Format::num($d['tokens']).' token'.($d['failed'] ? ' · '.$d['failed'].' lỗi' : '')];
        }
        $models = $runs->filter(fn ($r) => $r->actual_model)->groupBy(fn ($r) => $r->actual_model['provider_name'].' · '.$r->actual_model['model_id'])->sortByDesc(fn ($g) => $g->count())->take(6);
        $topModel = max(1, $models->first()?->count() ?? 0);

        return [
            'days' => $days,
            'stats' => [
                ['id' => 'runs', 'icon' => '#i-chat', 'label' => 'Lượt chạy', 'value' => Format::num($runs->count()), 'hint' => Format::num($today['runs']).' hôm nay', 'warn' => false],
                ['id' => 'tokens', 'icon' => '#i-gauge', 'label' => 'Token đã dùng', 'value' => Format::compact($runs->sum('used_tokens')), 'hint' => Format::compact($today['tokens']).' hôm nay', 'warn' => false],
                ['id' => 'failed', 'icon' => '#i-alert', 'label' => 'Tỉ lệ lỗi', 'value' => Format::percent($failed, $runs->count()), 'hint' => Format::num($failed).' lượt lỗi', 'warn' => $runs->count() && $failed / $runs->count() > .1],
                ['id' => 'users', 'icon' => '#i-users', 'label' => 'Người dùng', 'value' => Format::num(User::count()), 'hint' => Format::num(Workspace::whereNull('user_id')->where('expires_at', '>', now())->count()).' khách đang hoạt động', 'warn' => false],
            ],
            'active' => GenerationRun::whereIn('status', ['queued', 'running'])->count(),
            'conversations' => Format::num(Conversation::where('temporary', false)->count()),
            'peak' => Format::num($peak),
            'bars' => $bars,
            'modes' => collect(self::MODES)->map(fn ($label, $mode) => ['mode' => $mode, 'label' => $label, 'count' => Format::num($n = $runs->where('mode', $mode)->count()), 'pct' => $runs->count() ? round($n / $runs->count() * 100) : 0])->values()->all(),
            'models' => $models->values()->map(fn ($g, $i) => ['id' => 'm'.$i, 'name' => $models->keys()[$i], 'count' => Format::num($g->count()), 'tokens' => Format::compact($g->sum('used_tokens')), 'pct' => round($g->count() / $topModel * 100)])->all(),
            'failures' => $runs->where('status', 'failed')->sortByDesc('created_at')->take(8)->map(fn ($r) => ['id' => $r->id, 'error' => $r->error ?: 'Không rõ lỗi',
                'meta' => implode(' · ', array_filter([self::MODES[$r->mode] ?? $r->mode, $r->actual_model['model_id'] ?? null, Format::when($r->created_at)]))])->values()->all(),
        ];
    }
}

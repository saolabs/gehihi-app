<?php

namespace App\Modules\AiProviders\Services;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiProviders\Repositories\ProviderRepositoryInterface;
use App\Modules\AiRuntime\Models\GenerationRun;
use App\Support\Format;

/** Tình trạng provider hệ thống cho trang quản trị: key tạm khóa, lượt dùng và tỉ lệ lỗi 7 ngày. */
class ProviderHealth
{
    public function __construct(private ProviderRepositoryInterface $providers) {}

    public function providers(): array
    {
        $runs = GenerationRun::where('created_at', '>=', now()->subDays(6)->startOfDay())->get(['status', 'actual_model'])->filter(fn ($r) => $r->actual_model)->groupBy(fn ($r) => $r->actual_model['provider_id']);

        return [
            'rows' => Provider::whereNull('user_id')->orderBy('kind')->orderBy('name')->get()->map(function (Provider $p) use ($runs) {
                $keys = count($p->secrets ?? []);
                $cooling = $this->providers->coolingKeys($p);
                $used = $runs[$p->id] ?? collect();
                $failed = $used->where('status', 'failed')->count();
                $status = ! $p->enabled ? 'off' : ($keys && $cooling >= $keys ? 'down' : ($cooling || ($used->count() && $failed / $used->count() > .2) ? 'warn' : 'ok'));

                return ['id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'kind_label' => $p->kind === 'search' ? 'Tìm kiếm' : 'Mô hình AI',
                    'type_label' => ProviderCatalog::TYPES[$p->type]['label'] ?? $p->type, 'is_default' => $p->is_default,
                    'status' => $status, 'status_label' => ['off' => 'Đã tắt', 'down' => 'Mọi key đang tạm khóa', 'warn' => 'Cần chú ý', 'ok' => 'Hoạt động'][$status],
                    'keys' => $cooling ? "$keys ($cooling tạm khóa)" : (string) $keys, 'models' => $p->kind === 'search' ? '—' : (string) count($p->models ?? []),
                    'runs' => Format::num($used->count()), 'fail_rate' => $used->count() ? Format::percent($failed, $used->count()) : '—',
                    'tested' => $p->tested_at ? ($p->test_status ?: 'Đã kiểm tra').' · '.Format::when($p->tested_at) : 'Chưa kiểm tra'];
            })->map(fn ($row) => [...$row, 'key' => Format::rowKey($row)])->values()->all(),
            'personal_users' => Provider::whereNotNull('user_id')->distinct()->count('user_id'),
            'personal_providers' => Provider::whereNotNull('user_id')->count(),
        ];
    }
}

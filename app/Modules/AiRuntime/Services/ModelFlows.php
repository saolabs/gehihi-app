<?php

namespace App\Modules\AiRuntime\Services;

use App\Modules\AiProviders\Services\ProviderService;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Repositories\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModelFlows
{
    public const STAGES = [
        'plan' => ['Lập kế hoạch', 'Tách câu hỏi và tạo truy vấn tìm kiếm.'],
        'assess' => ['Đánh giá bằng chứng', 'Kiểm tra nguồn đã thu thập và xác định phần còn thiếu.'],
        'report' => ['Tổng hợp báo cáo', 'Viết câu trả lời cuối cùng kèm trích dẫn nguồn.'],
    ];

    public function __construct(private ProviderService $providers, private WorkspaceRepositoryInterface $workspace) {}

    public static function rules(): array
    {
        $rules = ['flows' => ['required', 'array:plan,assess,report']];
        foreach (self::STAGES as $stage => $_) {
            $key = 'flows.'.$stage;
            $rules += [$key => ['present', 'nullable', 'array:provider_id,model_id,fallbacks'],
                $key.'.provider_id' => ['required_with:'.$key, 'uuid'],
                $key.'.model_id' => ['required_with:'.$key, 'string', 'max:200'],
                $key.'.fallbacks' => ['sometimes', 'array', 'max:1'],
                $key.'.fallbacks.*' => ['array:provider_id,model_id'],
                $key.'.fallbacks.*.provider_id' => ['required', 'uuid'],
                $key.'.fallbacks.*.model_id' => ['required', 'string', 'max:200']];
        }
        return $rules;
    }

    public function defaults(): array
    {
        $stored = DB::table('app_settings')->where('key', 'model_flows')->value('value');
        return array_replace(array_fill_keys(array_keys(self::STAGES), null), json_decode($stored ?: '{}', true));
    }

    private function personal(string $ws): array
    {
        return Workspace::findOrFail($ws)->preferences['model_flows'] ?? [];
    }

    public function page(?string $userId = null, ?string $ws = null): array
    {
        $defaults = $this->defaults();
        $flows = $ws ? array_replace(array_fill_keys(array_keys(self::STAGES), null), $this->personal($ws)) : $defaults;
        $options = [];
        foreach ($this->providers->catalog($userId) as $provider) {
            if ($provider['kind'] !== 'llm') continue;
            foreach ($provider['models'] as $model) {
                $options[] = ['value' => $provider['id'].'|'.$model,
                    'label' => ($provider['scope'] === 'system' ? 'Hệ thống' : 'Của bạn').' · '.$provider['name'].' · '.$model];
            }
        }
        $stages = [];
        foreach (self::STAGES as $id => [$label, $description]) {
            $selection = $flows[$id];
            $value = $selection ? $selection['provider_id'].'|'.$selection['model_id'] : '';
            $fallback = $selection['fallbacks'][0] ?? null;
            $fallbackValue = $fallback ? $fallback['provider_id'].'|'.$fallback['model_id'] : '';
            $stages[] = ['id' => $id, 'label' => $label, 'description' => $description, 'value' => $value,
                'fallback' => $fallbackValue,
                'fallback_unavailable' => $fallbackValue && !in_array($fallbackValue, array_column($options, 'value'), true),
                'unavailable' => $value && !in_array($value, array_column($options, 'value'), true),
                'system_model' => $defaults[$id]['model_id'] ?? null];
        }
        return ['stages' => $stages, 'options' => $options];
    }

    private function normalize(array $flows, ?string $userId): array
    {
        $catalog = collect($this->providers->catalog($userId))->where('kind', 'llm')->keyBy('id');
        $result = [];
        foreach (self::STAGES as $stage => [$label]) {
            $selection = $flows[$stage] ?? null;
            $result[$stage] = null;
            if (!$selection) continue;
            $candidates = [];
            foreach ([$selection, ...($selection['fallbacks'] ?? [])] as $candidate) {
                $provider = $catalog->get($candidate['provider_id']);
                if (!$provider || !in_array($candidate['model_id'], $provider['models'], true)) {
                    throw ValidationException::withMessages(['flows.'.$stage => $label.': provider/model không còn khả dụng hoặc bạn không có quyền sử dụng. Hãy chọn lại trong Luồng model.']);
                }
                $candidates[$candidate['provider_id'].'|'.$candidate['model_id']] = ['provider_id' => $candidate['provider_id'], 'model_id' => $candidate['model_id']];
            }
            $candidates = array_values($candidates);
            $result[$stage] = [...$candidates[0], 'fallbacks' => array_slice($candidates, 1)];
        }
        return $result;
    }

    public function save(array $flows, ?string $userId = null, ?string $ws = null): array
    {
        $flows = $this->normalize($flows, $userId);
        if ($ws) {
            $this->workspace->preferences($ws, ['model_flows' => $flows]);
        } else {
            DB::table('app_settings')->updateOrInsert(['key' => 'model_flows'], ['value' => json_encode($flows), 'updated_at' => now(), 'created_at' => now()]);
        }
        return $this->page($userId, $ws);
    }

    /** Freeze candidates when a run starts so retries/resume keep the same routing. */
    public function snapshot(string $ws, ?string $userId, array $inherited, string $thinking): array
    {
        $personal = $this->personal($ws);
        $flows = $this->defaults();
        foreach (self::STAGES as $stage => $_) {
            if (!empty($personal[$stage])) $flows[$stage] = $personal[$stage];
        }
        $flows = $this->normalize($flows, $userId);
        $out = [];
        foreach ($flows as $stage => $selection) {
            $out[$stage] = $selection ? $this->providers->resolve($selection, $userId, false, $thinking) : $inherited;
        }
        return $out;
    }

    public static function stage(string $operation): ?string
    {
        $stage = explode(':', $operation, 2)[0];
        return isset(self::STAGES[$stage]) ? $stage : null;
    }
}

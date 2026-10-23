<?php

namespace App\Modules\AiProviders\Services;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiProviders\Repositories\ProviderRepositoryInterface;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProviderService
{
    public function __construct(private ProviderRepositoryInterface $repo, private OutboundHttp $http) {}

    /** Luật kiểm tra form provider, dùng chung cho cài đặt cá nhân và trang quản trị. */
    public static function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'type' => ['required', Rule::in(array_keys(ProviderCatalog::TYPES))], 'base_url' => ['exclude_unless:type,openai_compatible,searxng', 'required', 'string', 'max:400'], 'api_keys' => ['nullable', 'string', 'max:16000'], 'clear_keys' => ['sometimes', 'boolean'], 'models' => ['nullable', 'string', 'max:20000'], 'default_model' => ['nullable', 'string', 'max:200'], 'enabled' => ['sometimes', 'boolean'], 'is_default' => ['sometimes', 'boolean'], 'vision_models' => ['nullable', 'string', 'max:20000'], 'reasoning_models' => ['nullable', 'string', 'max:20000'], 'vision' => ['sometimes', 'boolean'], 'reasoning' => ['sometimes', 'boolean']];
    }

    /** Dữ liệu điền sẵn cho form sửa: danh sách model dạng một-dòng-một-ID, không bao giờ kèm key. */
    public function form(Provider $p): array
    {
        $has = fn (string $cap) => array_values(array_filter($p->models ?? [], fn ($id) => $p->capabilities['models'][$id][$cap] ?? $p->capabilities[$cap] ?? false));

        return ['id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'kind' => $p->kind, 'base_url' => ProviderCatalog::editableBaseUrl($p->type) ? $p->base_url : '', 'models' => implode("\n", $p->models ?? []), 'default_model' => $p->default_model,
            'vision_models' => implode("\n", $has('vision')), 'reasoning_models' => implode("\n", $has('reasoning')), 'enabled' => $p->enabled, 'is_default' => $p->is_default, 'key_count' => count($p->secrets ?? [])];
    }

    public function catalog(?string $userId): array
    {
        return array_map(fn ($p) => $this->public($p), $this->repo->visible($userId));
    }

    public function managed(?string $owner): array
    {
        return array_map(fn ($p) => $this->public($p), $this->repo->managed($owner));
    }

    public function public(Provider $p): array
    {
        return [...$p->toArray(), 'base_url' => ProviderCatalog::editableBaseUrl($p->type) ? $p->base_url : '', 'scope' => $p->user_id ? 'user' : 'system', 'key_count' => count($p->secrets ?? [])];
    }

    public function draft(?string $id, ?string $owner, array $data): Provider
    {
        $old = $id ? $this->repo->find($id) : null;
        if ($old && $old->user_id !== $owner) {
            abort(404);
        }
        $type = $data['type'];
        $meta = ProviderCatalog::TYPES[$type] ?? null;
        if (! $meta) {
            throw ValidationException::withMessages(['type' => 'Loại provider không hỗ trợ.']);
        }
        $keys = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n]+/', $data['api_keys'] ?? '')))));
        if (! $keys && ! ($data['clear_keys'] ?? false) && $old?->type === $type) {
            $keys = $old->secrets ?? [];
        }
        if (! $keys && ! in_array($type, ['openai_compatible', 'searxng'])) {
            throw ValidationException::withMessages(['api_keys' => 'Provider cần ít nhất một API key.']);
        }
        $base = ProviderCatalog::editableBaseUrl($type) ? rtrim(trim($data['base_url'] ?? ''), '/') : '';
        if (ProviderCatalog::editableBaseUrl($type) && $base === '') {
            throw ValidationException::withMessages(['base_url' => 'Nhập Base URL của provider tùy chỉnh.']);
        }
        $this->http->options(ProviderCatalog::baseUrl($type, $base));
        $models = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $data['models'] ?? '')))));
        if ($meta['kind'] === 'llm' && ! $models) {
            throw ValidationException::withMessages(['models' => 'Nhập ít nhất một model ID hoặc tải danh sách model.']);
        }
        $default = trim($data['default_model'] ?? '') ?: ($models[0] ?? '');
        if ($default && ! in_array($default, $models, true)) {
            throw ValidationException::withMessages(['default_model' => 'Model mặc định phải nằm trong danh sách cho phép.']);
        }
        if (count($keys) > 20) {
            throw ValidationException::withMessages(['api_keys' => 'Tối đa 20 key cho mỗi provider.']);
        }
        $capabilities = [];
        foreach (['vision', 'reasoning'] as $capability) {
            $field = $capability.'_models';
            $selected = array_key_exists($field, $data) ? array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $data[$field] ?? '')))) : (($data[$capability] ?? false) ? $models : []);
            if (array_diff($selected, $models)) {
                throw ValidationException::withMessages([$field => 'Model năng lực phải thuộc danh sách được phép dùng.']);
            }
            foreach ($models as $model) {
                $capabilities['models'][$model][$capability] = in_array($model, $selected, true);
            }
        }
        $p = new Provider(['name' => $data['name'], 'type' => $type, 'kind' => $meta['kind'], 'base_url' => $base, 'models' => $models, 'default_model' => $default, 'secrets' => $keys, 'capabilities' => $capabilities, 'enabled' => (bool) ($data['enabled'] ?? true), 'is_default' => (bool) ($data['is_default'] ?? false), 'user_id' => $owner]);
        if ($old) {
            $p->id = $old->id;
            $p->exists = true;
        }

        return $p;
    }

    public function save(?string $id, ?string $owner, array $data): array
    {
        $draft = $this->draft($id, $owner, $data);
        $attributes = $draft->toArray();
        unset($attributes['id'],$attributes['user_id']);
        // Pass plaintext through casts exactly once; don't double-encrypt the stored attribute.
        $attributes['secrets'] = $draft->secrets;

        return $this->public($this->repo->save($id, $owner, $attributes));
    }

    public function remove(string $id, ?string $owner): void
    {
        $this->repo->remove($id, $owner);
    }

    public function allowed(string $id, ?string $userId, string $kind = 'llm'): Provider
    {
        $p = $this->repo->find($id);
        abort_unless($p->enabled && $p->kind === $kind && (! $p->user_id || $p->user_id === $userId), 404);

        return $p;
    }

    public function assertSelection(array $selection, ?string $userId): void
    {
        foreach ([$selection, ...($selection['fallbacks'] ?? [])] as $s) {
            $p = $this->allowed($s['provider_id'] ?? '', $userId);
            if (! in_array($s['model_id'] ?? '', $p->models, true)) {
                throw ValidationException::withMessages(['selection' => 'Model không thuộc danh sách cho phép.']);
            }
        }
    }

    public function resolve(?array $selection, ?string $userId, bool $vision = false, string $mode = 'auto'): array
    {
        if (! $selection) {
            foreach ($this->repo->visible($userId) as $provider) {
                if ($provider->kind !== 'llm') {
                    continue;
                }
                foreach (array_unique([$provider->default_model, ...$provider->models]) as $model) {
                    if ($this->supports($provider, $model, $vision, $mode)) {
                        $selection = ['provider_id' => $provider->id, 'model_id' => $model];
                        break 2;
                    }
                }
            }
            if (! $selection) {
                throw new ProviderFailure('Chưa có model phù hợp. Hãy cấu hình provider trong Cài đặt hoặc nhờ quản trị cấu hình provider hệ thống.');
            }
        }
        $this->assertSelection($selection, $userId);
        $out = [];
        $seen = [];
        foreach ([$selection, ...($selection['fallbacks'] ?? [])] as $s) {
            $p = $this->allowed($s['provider_id'], $userId);
            if (! $this->supports($p, $s['model_id'], $vision, $mode)) {
                continue;
            }$key = $p->id.':'.$s['model_id'];
            if (isset($seen[$key])) {
                continue;
            }$seen[$key] = true;
            $out[] = ['provider_id' => $p->id, 'model_id' => $s['model_id']];
        }
        if (! $out) {
            throw new ProviderFailure('Model được chọn chưa được cấu hình cho ảnh hoặc chế độ suy luận. Hãy đổi model hoặc cập nhật năng lực model.');
        }

return $out;
    }

    private function supports(Provider $provider, string $model, bool $vision, string $mode): bool
    {
        $capabilities = $provider->capabilities['models'][$model] ?? $provider->capabilities ?? [];

        return (! $vision || ($capabilities['vision'] ?? false)) && ($mode !== 'reasoning' || ($capabilities['reasoning'] ?? false));
    }

    public function discover(?string $id, ?string $owner, array $data): array
    {
        // Discovery doesn't require a model to have been chosen yet.
        if (trim($data['models'] ?? '') === '') {
            $data['models'] = '__discovery__';
        }$data['default_model'] = '';
        // Capabilities belong to the saved selection, not the temporary discovery model.
        unset($data['vision_models'], $data['reasoning_models'], $data['vision'], $data['reasoning']);
        $p = $this->draft($id, $owner, $data);
        if ($p->kind === 'search') {
            return [];
        }
        if ($p->type === 'google_vertex') {
            return ProviderCatalog::VERTEX_MODELS;
        }
        $headers = $this->headers($p, ($p->secrets ?? [])[0] ?? null);
        $base = ProviderCatalog::baseUrl($p->type, $p->base_url).'/models';
        $ids = [];
        $cursor = null;
        for ($page = 0; $page < 20; $page++) {
            $google = $p->type === 'google';
            $query = $google ? ['pageSize' => 1000] : ($p->type === 'anthropic' ? ['limit' => 1000] : []);
            if ($cursor) {
                $query[$google ? 'pageToken' : 'after_id'] = $cursor;
            }
            $r = $this->http->send('GET', $base.($query ? '?'.http_build_query($query) : ''), $headers);
            if (! $r->successful()) {
                throw new ProviderFailure('Không tải được danh sách model: HTTP '.$r->status(), $r->status());
            }
            $payload = $this->http->json($r);
            foreach (($payload['data'] ?? $payload['models'] ?? $payload['publisherModels'] ?? []) as $row) {
                if ($p->type === 'google' && ! in_array('generateContent', $row['supportedGenerationMethods'] ?? [], true)) {
                    continue;
                }$model = $row['id'] ?? $row['name'] ?? null;
                if ($model) {
                    $model = preg_replace('#^(?:publishers/google/)?models/#', '', $model);
                    $ids[] = $model;
                }
            }
            $cursor = $payload['nextPageToken'] ?? (($payload['has_more'] ?? false) ? ($payload['last_id'] ?? null) : null);
            if (! $cursor) {
                break;
            }
        }
        if ($cursor) {
            throw new ProviderFailure('Danh sách model chưa tải đủ. Hãy nhập model thủ công.');
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    public function headers(Provider $p, ?string $key): array
    {
        return ['Accept' => 'application/json'] + match ($p->type) {
            'google', 'google_vertex' => $key ? ['x-goog-api-key' => $key] : [], 'anthropic' => ['x-api-key' => $key ?? '', 'anthropic-version' => '2023-06-01'],default => $key ? ['Authorization' => 'Bearer '.$key] : []
        };
    }
}

<?php

namespace App\Modules\AiProviders\Http\Controllers\Admin;

use App\Http\Controllers\AdminController;
use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiProviders\Services\ProviderCatalog;
use App\Modules\AiProviders\Services\ProviderHealth;
use App\Modules\AiProviders\Services\ProviderProbe;
use App\Modules\AiProviders\Services\ProviderService;
use Illuminate\Http\Request;

/** Provider hệ thống (user_id = null): phục vụ khách và tài khoản chưa có provider riêng. */
class ProviderController extends AdminController
{
    public function __construct(private ProviderHealth $health, private ProviderService $providers, private ProviderProbe $probe) {}

    public function index()
    {
        return $this->response($this->health->providers());
    }

    public function create()
    {
        return $this->response($this->form(['id' => null, 'name' => '', 'type' => 'google', 'kind' => 'llm', 'base_url' => '', 'models' => '', 'default_model' => '', 'vision_models' => '', 'reasoning_models' => '', 'enabled' => true, 'is_default' => false, 'key_count' => 0]), '@RAW:modules.providers.form');
    }

    public function edit(string $id)
    {
        return $this->response($this->form($this->providers->form(Provider::whereNull('user_id')->findOrFail($id))), '@RAW:modules.providers.form');
    }

    private function form(array $provider): array
    {
        $types = array_map(fn ($value, $meta) => ['value' => $value, 'label' => $meta['label'], 'kind' => $meta['kind'], 'base_url_editable' => ProviderCatalog::editableBaseUrl($value)], array_keys(ProviderCatalog::TYPES), ProviderCatalog::TYPES);

        $provider['base_url_editable'] = ProviderCatalog::editableBaseUrl($provider['type']);
        $provider['keys_hint'] = $provider['key_count'] ? 'Đã lưu '.$provider['key_count'].' key. Để trống để giữ nguyên.' : 'Mỗi key một dòng.';
        $provider['model_ids'] = array_values(array_filter(explode("\n", $provider['models'])));
        $provider['model_choices'] = array_map(fn ($id) => ['id' => $id, 'selected' => true, 'stale' => false,
            'vision' => in_array($id, explode("\n", $provider['vision_models']), true),
            'reasoning' => in_array($id, explode("\n", $provider['reasoning_models']), true)], $provider['model_ids']);

        return ['provider' => $provider, 'types' => $types];
    }

    public function store(Request $r)
    {
        $this->providers->save(null, null, $r->validate(ProviderService::rules()));

        return $this->result(['saved' => true], 201);
    }

    public function update(Request $r, string $id)
    {
        Provider::whereNull('user_id')->findOrFail($id);
        $this->providers->save($id, null, $r->validate(ProviderService::rules()));

        return $this->result(['saved' => true]);
    }

    public function destroy(string $id)
    {
        $this->providers->remove($id, null);

        return $this->result($this->health->providers());
    }

    public function test(Request $r)
    {
        return $this->result(['results' => $this->probe->test($this->providers->draft($r->input('id'), null, $r->validate(ProviderService::rules())))]);
    }

    public function discover(Request $r)
    {
        return $this->result(['models' => $this->providers->discover($r->input('id'), null, $r->validate(ProviderService::rules()))]);
    }
}

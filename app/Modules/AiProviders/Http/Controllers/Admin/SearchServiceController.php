<?php

namespace App\Modules\AiProviders\Http\Controllers\Admin;

use App\Http\Controllers\AdminController;
use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiProviders\Services\ProviderCatalog;
use App\Modules\AiProviders\Services\ProviderHealth;
use App\Modules\AiProviders\Services\ProviderProbe;
use App\Modules\AiProviders\Services\ProviderService;
use Illuminate\Http\Request;

class SearchServiceController extends AdminController
{
    public function __construct(private ProviderHealth $health, private ProviderService $providers, private ProviderProbe $probe) {}

    private function account(string $id): Provider
    {
        return Provider::whereNull('user_id')->where('kind', 'search')->findOrFail($id);
    }

    private function groups(): array
    {
        $rows = $this->health->providers('search')['rows'];
        $groups = [];
        foreach (ProviderCatalog::types('search') as $type => $meta) {
            $accounts = array_values(array_filter($rows, fn ($row) => $row['type'] === $type));
            $groups[] = ['type' => $type, 'label' => $meta['label'], 'editable_base' => ProviderCatalog::editableBaseUrl($type),
                'accounts' => $accounts, 'key_count' => array_sum(array_column($accounts, 'key_count'))];
        }
        return $groups;
    }

    public function index(Request $r)
    {
        return $this->response(['groups' => $this->groups(), 'editAccount' => $r->query('edit') ? $this->providers->form($this->account($r->query('edit'))) : null]);
    }

    public function edit(string $id)
    {
        return $this->result(['account' => $this->providers->form($this->account($id))]);
    }

    public function store(Request $r)
    {
        $this->providers->save(null, null, $r->validate(ProviderService::rules('search')));
        return $this->result(['groups' => $this->groups()], 201);
    }

    public function update(Request $r, string $id)
    {
        $this->account($id);
        $this->providers->save($id, null, $r->validate(ProviderService::rules('search')));
        return $this->result(['groups' => $this->groups()]);
    }

    public function destroy(string $id)
    {
        $this->account($id);
        $this->providers->remove($id, null);
        return $this->result(['groups' => $this->groups()]);
    }

    public function test(Request $r)
    {
        if ($r->input('id')) $this->account($r->input('id'));
        $data = $r->validate([...ProviderService::rules('search'), 'key_index' => ['sometimes', 'integer', 'min:1', 'max:20']]);
        return $this->result(['results' => $this->probe->test($this->providers->draft($r->input('id'), null, $data), $data['key_index'] ?? null)]);
    }
}

<?php

namespace App\Modules\AiProviders\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Modules\AiProviders\Services\ProviderCatalog;
use App\Modules\AiProviders\Services\ProviderProbe;
use App\Modules\AiProviders\Services\ProviderService;
use Illuminate\Http\Request;

class ProviderController extends ApiController
{
    public function __construct(private ProviderService $service, private ProviderProbe $probe) {}

    private function owner(Request $r): ?string
    {
        abort_unless($r->user(), 401);
        if ($r->boolean('system')) {
            abort_unless($r->user()->is_admin, 403);

            return null;
        }

return $r->user()->id;
    }

    private function data(Request $r): array
    {
        return $r->validate(ProviderService::rules());
    }

    public function index(Request $r)
    {
        return $this->ok(['providers' => $this->service->managed($this->owner($r)), 'types' => ProviderCatalog::TYPES]);
    }

    public function save(Request $r, ?string $id = null)
    {
        $owner = $this->owner($r);

        return $this->ok($this->service->save($id, $owner, $this->data($r)));
    }

    public function delete(Request $r, string $id)
    {
        $this->service->remove($id, $this->owner($r));

        return $this->ok();
    }

    public function models(Request $r)
    {
        return $this->ok($this->service->discover($r->input('id'), $this->owner($r), $this->data($r)));
    }

    public function test(Request $r)
    {
        $data = $r->validate([...ProviderService::rules(), 'key_index' => ['sometimes', 'integer', 'min:1', 'max:20']]);
        return $this->ok(['results' => $this->probe->test($this->service->draft($r->input('id'), $this->owner($r), $data), $data['key_index'] ?? null)]);
    }
}

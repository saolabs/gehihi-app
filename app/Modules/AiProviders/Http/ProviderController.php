<?php

namespace App\Modules\AiProviders\Http;

use App\Http\Controllers\ApiController;
use App\Modules\AiProviders\Services\ProviderCatalog;
use App\Modules\AiProviders\Services\ProviderFailure;
use App\Modules\AiProviders\Services\ProviderService;
use App\Modules\AiProviders\Services\TextGateway;
use App\Modules\Research\Services\SearchGateway;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProviderController extends ApiController
{
    public function __construct(private ProviderService $service, private TextGateway $gateway, private SearchGateway $search) {}

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
        return $r->validate(['name' => ['required', 'string', 'max:100'], 'type' => ['required', Rule::in(array_keys(ProviderCatalog::TYPES))], 'base_url' => ['nullable', 'string', 'max:400'], 'api_keys' => ['nullable', 'string', 'max:16000'], 'clear_keys' => ['sometimes', 'boolean'], 'models' => ['nullable', 'string', 'max:20000'], 'default_model' => ['nullable', 'string', 'max:200'], 'enabled' => ['sometimes', 'boolean'], 'is_default' => ['sometimes', 'boolean'], 'vision_models' => ['nullable', 'string', 'max:20000'], 'reasoning_models' => ['nullable', 'string', 'max:20000'], 'vision' => ['sometimes', 'boolean'], 'reasoning' => ['sometimes', 'boolean']]);
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
        $p = $this->service->draft($r->input('id'), $this->owner($r), $this->data($r));
        $results = [];
        foreach (($p->secrets ?? []) ?: [null] as $i => $key) {
            $p->secrets = $key ? [$key] : [];
            $start = microtime(true);
            try {
                if ($p->kind === 'search') {
                    $out = ['text' => $this->search->probe($p)];
                } else {
                    $out = $this->gateway->generate($p, $p->default_model, [['role' => 'user', 'content' => 'Reply with exactly one word: OK']], 64, fn ($text) => null, fn () => null, true);
                }$results[] = ['key' => $i + 1, 'ok' => true, 'text' => $out['text'], 'latency_ms' => (int) ((microtime(true) - $start) * 1000)];
            } catch (\Throwable $e) {
                $results[] = ['key' => $i + 1, 'ok' => false, 'message' => $e instanceof ProviderFailure ? $e->getMessage() : 'Không kết nối được provider.'];
            }
        }

        return $this->ok(['results' => $results]);
    }
}

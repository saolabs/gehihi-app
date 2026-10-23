<?php

namespace App\Modules\AiProviders\Services;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\Research\Services\SearchGateway;

/** Kiểm tra từng key của một provider nháp (chưa lưu); dùng chung cho cài đặt cá nhân và trang quản trị. */
class ProviderProbe
{
    public function __construct(private TextGateway $gateway, private SearchGateway $search) {}

    public function test(Provider $p): array
    {
        $results = [];
        foreach (($p->secrets ?? []) ?: [null] as $i => $key) {
            $p->secrets = $key ? [$key] : [];
            $start = microtime(true);
            try {
                $probe = clone $p;
                $vertexThinking = $p->type === 'google_vertex' && in_array($p->default_model, ProviderCatalog::VERTEX_MODELS, true);
                if ($vertexThinking) {
                    // Curated Gemini models can spend a 64-token probe entirely
                    // on thinking. Use seo-expert's probe budget and low effort
                    // without changing the user's saved capability selection.
                    $capabilities = $probe->capabilities ?? [];
                    $capabilities['models'][$p->default_model]['reasoning'] = true;
                    $probe->capabilities = $capabilities;
                }
                $out = $p->kind === 'search'
                    ? ['text' => $this->search->probe($p)]
                    : $this->gateway->generate($probe, $p->default_model, [['role' => 'user', 'content' => 'Reply with exactly one word: OK']], $vertexThinking ? 1024 : 64, fn ($text) => null, fn () => null, true, $vertexThinking ? 'fast' : 'auto');
                $results[] = ['key' => $i + 1, 'ok' => true, 'text' => $out['text'], 'latency_ms' => (int) ((microtime(true) - $start) * 1000)];
            } catch (\Throwable $e) {
                $results[] = ['key' => $i + 1, 'ok' => false, 'message' => $e instanceof ProviderFailure ? $e->getMessage() : 'Không kết nối được provider.'];
            }
        }

        return $results;
    }
}

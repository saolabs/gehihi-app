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
                $out = $p->kind === 'search'
                    ? ['text' => $this->search->probe($p)]
                    : $this->gateway->generate($p, $p->default_model, [['role' => 'user', 'content' => 'Reply with exactly one word: OK']], 64, fn ($text) => null, fn () => null, true);
                $results[] = ['key' => $i + 1, 'ok' => true, 'text' => $out['text'], 'latency_ms' => (int) ((microtime(true) - $start) * 1000)];
            } catch (\Throwable $e) {
                $results[] = ['key' => $i + 1, 'ok' => false, 'message' => $e instanceof ProviderFailure ? $e->getMessage() : 'Không kết nối được provider.'];
            }
        }

        return $results;
    }
}

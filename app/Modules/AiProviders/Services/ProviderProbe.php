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
                // Gemini 2.5/3 (cả Gemini API lẫn Vertex) suy nghĩ mặc định và có thể tiêu hết probe 64 token
                // vào phần nghĩ → không có chữ. Dùng ngân sách của seo-expert, suy luận thấp,
                // không đổi lựa chọn năng lực đã lưu. Model cũ hơn không nhận thinkingConfig nên giữ nguyên.
                $geminiThinking = in_array($p->type, ['google', 'google_vertex'], true) && preg_match('/^gemini-(2\.5|[3-9])/', (string) $p->default_model);
                if ($geminiThinking) {
                    $capabilities = $probe->capabilities ?? [];
                    $capabilities['models'][$p->default_model]['reasoning'] = true;
                    $probe->capabilities = $capabilities;
                }
                $out = $p->kind === 'search'
                    ? ['text' => $this->search->probe($p)]
                    : $this->gateway->generate($probe, $p->default_model, [['role' => 'user', 'content' => 'Reply with exactly one word: OK']], $geminiThinking ? 1024 : 64, fn ($text) => null, fn () => null, true, $geminiThinking ? 'fast' : 'auto');
                $results[] = ['key' => $i + 1, 'ok' => true, 'text' => $out['text'], 'latency_ms' => (int) ((microtime(true) - $start) * 1000)];
            } catch (\Throwable $e) {
                $results[] = ['key' => $i + 1, 'ok' => false, 'message' => $e instanceof ProviderFailure ? $e->getMessage() : 'Không kết nối được provider.'];
            }
        }

        return $results;
    }
}

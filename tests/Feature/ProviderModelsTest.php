<?php

namespace Tests\Feature;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiProviders\Services\TextGateway;
use App\Modules\AiProviders\Services\ProviderCatalog;
use App\Modules\Research\Services\SearchGateway;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['gehihi.egress_allowlist' => ['api.example.test', ...array_values(array_filter(array_map(fn ($meta) => parse_url($meta['base'], PHP_URL_HOST), ProviderCatalog::TYPES)))]]);
    }

    private function user(bool $admin = false): User
    {
        return User::create(['name' => 'Model editor', 'email' => 'models@example.test', 'password' => 'test-password', 'is_admin' => $admin]);
    }

    private function form(): array
    {
        return ['name' => 'Vertex', 'type' => 'google_vertex', 'api_keys' => 'private-vertex-key', 'models' => "gemini-text\ngemini-vision", 'default_model' => 'gemini-vision', 'vision_models' => 'gemini-vision', 'reasoning_models' => 'gemini-text', 'enabled' => true];
    }

    public function test_vertex_saves_encrypted_keys_and_preserves_model_capabilities_on_edit(): void
    {
        $this->actingAs($this->user());
        $id = $this->postJson('/api/providers', [...$this->form(), 'base_url' => 'https://ignored.invalid/v1'])->assertOk()->json('data.id');
        $p = Provider::findOrFail($id);
        $this->assertSame('', $p->base_url);
        $this->assertTrue($p->capabilities['models']['gemini-vision']['vision']);
        $this->assertStringNotContainsString('private-vertex-key', $p->getRawOriginal('secrets'));
        $this->patchJson('/api/providers/'.$id, [...$this->form(), 'api_keys' => ''])->assertOk();
        $this->assertSame(['private-vertex-key'], $p->fresh()->secrets);
        $this->assertStringNotContainsString('private-vertex-key', $this->getJson('/api/providers')->assertOk()->getContent());
        $this->patchJson('/api/providers/'.$id, [...$this->form(), 'default_model' => 'unknown'])->assertUnprocessable();
    }

    public function test_vertex_uses_curated_catalog_without_upstream_auth_or_overwriting_saved_selection(): void
    {
        $this->actingAs($this->user());
        $id = $this->postJson('/api/providers', $this->form())->assertOk()->json('data.id');
        Http::fake(['aiplatform.googleapis.com/*' => Http::response([], 401)]);
        $this->postJson('/api/providers/discover', [...$this->form(), 'id' => $id, 'api_keys' => '', 'models' => ''])
            ->assertOk()->assertJsonPath('data', ['gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite']);
        Http::assertNothingSent();
        $this->assertSame(['gemini-text', 'gemini-vision'], Provider::findOrFail($id)->models);
    }

    public function test_google_catalog_still_paginates_and_filters_non_generation_models(): void
    {
        $this->actingAs($this->user());
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['models' => [['name' => 'models/gemini-text', 'supportedGenerationMethods' => ['generateContent']], ['name' => 'models/embedding', 'supportedGenerationMethods' => ['embedContent']]], 'nextPageToken' => 'next'])
            ->push(['models' => [['name' => 'models/gemini-vision', 'supportedGenerationMethods' => ['generateContent']]]])]);
        $this->postJson('/api/providers/discover', [...$this->form(), 'type' => 'google', 'models' => ''])
            ->assertOk()->assertJsonPath('data', ['gemini-text', 'gemini-vision']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'pageToken=next') && $r->hasHeader('x-goog-api-key', 'private-vertex-key'));
    }

    public function test_vertex_stream_uses_google_payload_and_ignores_thought_text(): void
    {
        $p = Provider::create(['name' => 'Vertex', 'type' => 'google_vertex', 'kind' => 'llm', 'base_url' => 'https://old-override.invalid/v1', 'secrets' => ['vertex-key'], 'models' => ['gemini-test'], 'default_model' => 'gemini-test']);
        $event = ['candidates' => [['content' => ['parts' => [['text' => 'private thought', 'thought' => true], ['text' => 'OK']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['totalTokenCount' => 19]];
        Http::fake(['aiplatform.googleapis.com/*' => Http::response('data: '.json_encode($event)."\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
        $pieces = [];
        $result = app(TextGateway::class)->generate($p, 'gemini-test', [['role' => 'system', 'content' => 'Be concise'], ['role' => 'user', 'content' => 'Describe', 'images' => [['mime' => 'image/png', 'data' => 'aW1hZ2U=']]]], 64, function ($text) use (&$pieces) { $pieces[] = $text; }, fn () => null);
        $this->assertSame(['OK'], $pieces);
        $this->assertSame(['text' => 'OK', 'tokens' => 19, 'metering' => 'reported'], $result);
        Http::assertSent(fn ($r) => $r->url() === ProviderCatalog::TYPES['google_vertex']['base'].'/models/gemini-test:streamGenerateContent?alt=sse' && $r->hasHeader('x-goog-api-key', 'vertex-key') && $r['systemInstruction']['parts'][0]['text'] === 'Be concise' && $r['contents'][0]['parts'][1]['inlineData']['mimeType'] === 'image/png');
    }

    public function test_curated_vertex_key_probes_leave_room_for_text_without_changing_capabilities(): void
    {
        $cases = [...array_map(fn ($m) => ['google_vertex', $m], ProviderCatalog::VERTEX_MODELS), ['google', 'gemini-3.5-flash'], ['google', 'gemini-2.5-flash']];
        foreach ($cases as [$type, $model]) {
            $p = new Provider(['name' => 'Probe', 'type' => $type, 'kind' => 'llm', 'base_url' => '', 'secrets' => ['probe-key'], 'models' => [$model], 'default_model' => $model, 'capabilities' => ['models' => [$model => ['reasoning' => false]]]]);
            Http::fake(function ($r) {
                $thinking = $r['generationConfig']['thinkingConfig'] ?? [];
                $hasBudget = $r['generationConfig']['maxOutputTokens'] >= 1024 && (($thinking['thinkingLevel'] ?? null) === 'low' || ($thinking['thinkingBudget'] ?? null) === 0);
                $event = ['candidates' => [['content' => ['parts' => $hasBudget ? [['text' => 'OK']] : []], 'finishReason' => $hasBudget ? 'STOP' : 'MAX_TOKENS']]];

                return Http::response('data: '.json_encode($event)."\n\n", 200, ['Content-Type' => 'text/event-stream']);
            });
            $results = app(\App\Modules\AiProviders\Services\ProviderProbe::class)->test($p);
            $this->assertTrue($results[0]['ok'], $model);
            $this->assertSame('OK', $results[0]['text']);
            $this->assertFalse($p->capabilities['models'][$model]['reasoning']);
        }
    }

    public function test_gemini_invalid_key_400_is_reported_as_rejected_key_and_rotates(): void
    {
        $invalid = ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'details' => [['reason' => 'API_KEY_INVALID']]]];
        Http::fake(fn ($r) => $r->hasHeader('x-goog-api-key', 'bad-key')
            ? Http::response($invalid, 400)
            : Http::response('data: '.json_encode(['candidates' => [['content' => ['parts' => [['text' => 'OK']]], 'finishReason' => 'STOP']]])."\n\n", 200, ['Content-Type' => 'text/event-stream']));
        $form = ['name' => 'Gemini', 'type' => 'google', 'api_keys' => 'bad-key', 'models' => 'gemini-2.0-flash', 'default_model' => 'gemini-2.0-flash'];
        $service = app(\App\Modules\AiProviders\Services\ProviderService::class);
        try {
            $service->discover(null, null, $form);
            $this->fail('discover phải báo key sai');
        } catch (\App\Modules\AiProviders\Services\ProviderFailure $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('từ chối API key', $e->getMessage());
        }
        $results = app(\App\Modules\AiProviders\Services\ProviderProbe::class)->test($service->draft(null, null, [...$form, 'api_keys' => "bad-key\ngood-key"]));
        $this->assertStringContainsString('từ chối API key', $results[0]['message']);
        $this->assertTrue($results[1]['ok']);
        $saved = Provider::create(['name' => 'G', 'type' => 'google', 'kind' => 'llm', 'base_url' => '', 'secrets' => ['bad-key', 'good-key'], 'models' => ['gemini-2.0-flash'], 'default_model' => 'gemini-2.0-flash']);
        $out = app(\App\Modules\AiProviders\Services\TextGateway::class)->generate($saved, 'gemini-2.0-flash', [['role' => 'user', 'content' => 'hi']], 64, fn () => null, fn () => null);
        $this->assertSame('OK', $out['text']); // key sai bị bỏ qua, chuyển sang key kế
    }

    public function test_admin_form_renders_saved_models_without_client_only_helpers(): void
    {
        $this->actingAs($this->user(true));
        app(\App\Modules\AiProviders\Services\ProviderService::class)->save(null, null, $this->form());
        $p = Provider::firstOrFail();
        $this->withHeaders(['Accept' => 'text/html'])->get('/admin/providers/'.$p->id)->assertOk()->assertSee('gemini-vision')->assertSee('model-picker-grid', false)->assertDontSee('name="base_url"', false);
        $this->get('/admin/providers/create')->assertOk()->assertSee('Google Cloud (Vertex AI)');
    }

    public function test_preset_providers_ignore_submitted_urls_and_custom_providers_require_them(): void
    {
        $this->actingAs($this->user());
        foreach (ProviderCatalog::TYPES as $type => $meta) {
            if (ProviderCatalog::editableBaseUrl($type)) continue;
            $id = $this->postJson('/api/providers', [...$this->form(), 'type' => $type, 'base_url' => ['unexpected' => 'input']])
                ->assertOk()->assertJsonPath('data.base_url', '')->json('data.id');
            $this->assertSame('', Provider::findOrFail($id)->base_url);
        }
        foreach (['openai_compatible', 'searxng'] as $type) {
            $this->postJson('/api/providers', [...$this->form(), 'type' => $type])->assertUnprocessable()->assertJsonValidationErrors('base_url');
            $this->postJson('/api/providers', [...$this->form(), 'type' => $type, 'base_url' => 'https://api.example.test/v1/'])
                ->assertOk()->assertJsonPath('data.base_url', 'https://api.example.test/v1');
        }
    }

    public function test_legacy_search_provider_ignores_stored_endpoint_override(): void
    {
        $p = Provider::create(['name' => 'Serper', 'type' => 'serper', 'kind' => 'search', 'base_url' => 'https://old-override.invalid', 'secrets' => ['search-key'], 'models' => [], 'default_model' => '']);
        Http::fake(['google.serper.dev/*' => Http::response(['organic' => [['title' => 'Result', 'link' => 'https://example.com/page', 'snippet' => 'Source']]])]);
        $result = app(SearchGateway::class)->search('query', null, [], fn () => null, [$p]);
        $this->assertCount(1, $result['hits']);
        Http::assertSent(fn ($r) => $r->url() === 'https://google.serper.dev/search' && $r->hasHeader('X-API-KEY', 'search-key'));
    }

    public function test_admin_discovery_reports_provider_failures_without_generic_server_error(): void
    {
        $this->actingAs($this->user(true));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Upstream private detail']], 403)]);
        $this->postJson('/admin/providers/discover', [...$this->form(), 'type' => 'google'])->assertUnprocessable()
            ->assertJsonPath('message', 'Không tải được danh sách model: HTTP 403')
            ->assertDontSee('Upstream private detail');
    }

    public function test_admin_vertex_catalog_lists_three_models_without_calling_google(): void
    {
        $this->actingAs($this->user(true));
        Http::fake(['aiplatform.googleapis.com/*' => Http::response([], 401)]);
        $this->postJson('/admin/providers/discover', [...$this->form(), 'models' => ''])->assertOk()
            ->assertJsonPath('data.models', ['gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite']);
        Http::assertNothingSent();
    }


    /** Gửi một lượt qua gateway và trả body request thực gửi đi. */
    private function sentBody(string $type, string $model, string $thinking, bool $reasoning = true, int $limit = 4096): array
    {
        $p = Provider::create(['name' => $type, 'type' => $type, 'kind' => 'llm', 'base_url' => '', 'secrets' => ['k'], 'models' => [$model], 'default_model' => $model, 'capabilities' => ['models' => [$model => ['vision' => false, 'reasoning' => $reasoning]]]]);
        $body = null;
        Http::fake(function ($request) use (&$body) {
            $body = $request->data();

            return Http::response('', 200, ['Content-Type' => 'text/event-stream']);
        });
        try {
            app(TextGateway::class)->generate($p, $model, [['role' => 'system', 'content' => 'S'], ['role' => 'user', 'content' => 'Hi']], $limit, fn () => null, fn () => null, false, $thinking);
        } catch (\Throwable) {
            // Stream rỗng nên gateway báo chưa xong; chỉ cần body đã gửi.
        }
        $p->forceDelete();

        return $body;
    }

    public function test_thinking_mode_maps_to_each_provider_native_parameter(): void
    {
        $this->assertSame(['thinkingLevel' => 'high'], $this->sentBody('google', 'gemini-3-pro', 'reasoning')['generationConfig']['thinkingConfig']);
        $this->assertSame(['thinkingBudget' => 2048], $this->sentBody('google_vertex', 'gemini-2.5-flash', 'reasoning')['generationConfig']['thinkingConfig']);
        $this->assertSame(['thinkingBudget' => 0], $this->sentBody('google', 'gemini-2.5-flash', 'fast')['generationConfig']['thinkingConfig']);
        $this->assertSame(['thinkingBudget' => 128], $this->sentBody('google', 'gemini-2.5-pro', 'fast')['generationConfig']['thinkingConfig']);
        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 2048], $this->sentBody('anthropic', 'claude-x', 'reasoning')['thinking']);
        $this->assertArrayNotHasKey('thinking', $this->sentBody('anthropic', 'claude-x', 'fast'));
        $this->assertSame('high', $this->sentBody('openai', 'o-model', 'reasoning')['reasoning_effort']);
        $this->assertSame('low', $this->sentBody('openai', 'o-model', 'fast')['reasoning_effort']);
    }

    public function test_thinking_parameters_are_not_sent_to_undeclared_or_compatible_models(): void
    {
        $this->assertArrayNotHasKey('thinkingConfig', $this->sentBody('google', 'gemini-2.5-flash', 'reasoning', false)['generationConfig']);
        $this->assertArrayNotHasKey('reasoning_effort', $this->sentBody('openai', 'gpt-x', 'fast', false));
        $this->assertArrayNotHasKey('thinkingConfig', $this->sentBody('google', 'gemini-2.5-flash', 'auto')['generationConfig']);
        $this->assertArrayNotHasKey('thinking', $this->sentBody('anthropic', 'claude-x', 'reasoning', true, 1024));
    }
}

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

    public function test_vertex_catalog_paginates_and_does_not_overwrite_saved_selection(): void
    {
        $this->actingAs($this->user());
        $id = $this->postJson('/api/providers', $this->form())->assertOk()->json('data.id');
        Http::fake(['aiplatform.googleapis.com/*' => Http::sequence()
            ->push(['publisherModels' => [['name' => 'publishers/google/models/gemini-text'], ['name' => 'publishers/google/models/text-embedding'], ['name' => 'publishers/google/models/gemini-image']], 'nextPageToken' => 'next'])
            ->push(['publisherModels' => [['name' => 'publishers/google/models/gemini-vision']]])]);
        $this->postJson('/api/providers/discover', [...$this->form(), 'id' => $id, 'api_keys' => '', 'models' => ''])
            ->assertOk()->assertJsonPath('data', ['gemini-text', 'gemini-vision']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1beta1/publishers/google/models?') && str_contains($r->url(), 'pageToken=next') && $r->hasHeader('x-goog-api-key', 'private-vertex-key') && ! $r->hasHeader('Authorization'));
        $this->assertSame(['gemini-text', 'gemini-vision'], Provider::findOrFail($id)->models);
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

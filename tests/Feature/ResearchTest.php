<?php

namespace Tests\Feature;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiRuntime\Models\GenerationRun;
use App\Modules\AiRuntime\Services\Executor;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['gehihi.egress_allowlist' => ['api.example.test', 'search.example.test', 'source.example.test']]);
    }

    private function setupProviders(): void
    {
        Provider::create(['name' => 'AI', 'type' => 'openai_compatible', 'kind' => 'llm', 'base_url' => 'https://api.example.test/v1', 'models' => ['research-model'], 'default_model' => 'research-model', 'enabled' => true, 'is_default' => true]);
        Provider::create(['name' => 'Web', 'type' => 'searxng', 'kind' => 'search', 'base_url' => 'https://search.example.test', 'models' => [], 'enabled' => true, 'is_default' => true]);
    }

    private function startResearch(string $mode = 'deep'): array
    {
        $p = $this->postJson('/api/projects', ['name' => 'Research', 'instructions' => 'Always separate fact from inference.'])->assertOk()->json('data.id');
        $c = $this->postJson('/api/conversations', ['project_id' => $p])->assertCreated()->json('data.id');

        return $this->postJson('/api/conversations/'.$c.'/runs', ['request_id' => (string) Str::uuid(), 'message' => 'Compare options with sources.', 'mode' => $mode])->assertStatus(202)->json('data');
    }

    private function mockResearch(bool $invalid = false, bool $pagesFail = false): void
    {
        $assessments = 0;
        Http::fake(function ($request) use (&$assessments, $invalid, $pagesFail) {
            $url = $request->url();
            if (str_contains($url, 'api.example.test')) {
                $this->assertStringContainsString('Always separate fact', $request['messages'][0]['content']);
                $prompt = $request['messages'][1]['content'];
                $text = str_starts_with($prompt, 'Lập kế hoạch') ? json_encode(['questions' => ['Compare options'], 'queries' => ['first query']]) : (str_starts_with($prompt, 'Đánh giá') ? json_encode(['complete' => ++$assessments >= 2, 'missing' => $assessments < 2 ? ['More evidence'] : [], 'queries' => $assessments < 2 ? ['second query'] : []]) : ('## Findings'."\n\n".'A source-supported statement '.($invalid ? '[S999]' : '[S1]').'.'));

                return Http::response(['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 20]], 200);
            }
            if (str_contains($url, 'search.example.test')) {
                return Http::response(['results' => [['title' => 'Example source', 'url' => 'https://source.example.test/article', 'content' => 'A short snippet that is not the full page.']]], 200);
            }
            if (str_contains($url, 'source.example.test')) {
                return $pagesFail ? Http::response('Unavailable', 503) : Http::response('<html><head><title>Reliable evidence</title><meta property="article:published_time" content="2026-10-01"></head><body><main>'.str_repeat('This is source content describing the compared options and their limitations. ', 12).'</main></body></html>', 200, ['Content-Type' => 'text/html']);
            }
            throw new \RuntimeException('Unexpected URL');
        });
    }

    public function test_guest_deep_research_searches_reads_follows_up_and_synthesizes_with_sources(): void
    {
        $this->setupProviders();
        $this->mockResearch();
        $run = $this->startResearch();
        app(Executor::class)->execute($run['id']);
        $response = $this->getJson('/api/runs/'.$run['id'])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.sources.0.kind', 'page_content')->assertJsonPath('data.sources.0.id', 'S1');
        $this->assertStringContainsString('[S1]', $response->json('data.content'));
        $this->assertStringContainsString('https://source.example.test/article', $response->json('data.content'));
        $saved = GenerationRun::findOrFail($run['id']);
        $this->assertCount(2, $saved->checkpoint['queries']);
        $this->assertSame(2, $saved->checkpoint['round']);
        $this->assertSame(1, $saved->snapshot['instruction_revision']);
    }

    public function test_unknown_citation_is_marked_unverified_and_report_stays_partial(): void
    {
        $this->setupProviders();
        $this->mockResearch(true);
        $run = $this->startResearch();
        app(Executor::class)->execute($run['id']);
        $response = $this->getJson('/api/runs/'.$run['id'])->assertJsonPath('data.status', 'partial');
        $this->assertStringNotContainsString('[S999]', $response->json('data.content'));
        $this->assertStringContainsString('nguồn chưa xác minh', $response->json('data.content'));
    }

    public function test_snippets_do_not_become_full_page_evidence_when_reader_fails(): void
    {
        $this->setupProviders();
        $this->mockResearch(false, true);
        $run = $this->startResearch('quick');
        app(Executor::class)->execute($run['id']);
        $this->getJson('/api/runs/'.$run['id'])->assertJsonPath('data.status', 'partial')->assertJsonPath('data.sources.0.kind', 'search_snippet');
    }

    public function test_no_sources_returns_honest_partial_result_instead_of_fabricated_report(): void
    {
        $this->setupProviders();
        Http::fake(['api.example.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['questions' => ['Question'], 'queries' => ['query']])], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 10]]), 'search.example.test/*' => Http::response(['results' => []])]);
        $run = $this->startResearch('quick');
        app(Executor::class)->execute($run['id']);
        $this->getJson('/api/runs/'.$run['id'])->assertJsonPath('data.status', 'partial')->assertJsonPath('data.sources', []);
        Http::assertSentCount(2);
    }

    public function test_continue_reuses_successful_research_operations_instead_of_charging_them_again(): void
    {
        $this->setupProviders();
        $this->mockResearch(true);
        $run = $this->startResearch();
        app(Executor::class)->execute($run['id']);
        $before = GenerationRun::findOrFail($run['id'])->used_tokens;
        $requests = Http::recorded()->count();
        $this->postJson('/api/runs/'.$run['id'].'/resume')->assertOk();
        app(Executor::class)->execute($run['id']);
        Http::assertSentCount($requests);
        $this->assertSame($before, GenerationRun::findOrFail($run['id'])->used_tokens);
    }

    public function test_google_and_anthropic_receive_project_instructions_in_native_system_fields(): void
    {
        foreach (['google', 'anthropic'] as $type) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Provider::query()->delete();
            Provider::create(['name' => $type, 'type' => $type, 'kind' => 'llm', 'base_url' => 'https://api.example.test/v1', 'secrets' => ['test-key'], 'models' => ['test-model'], 'default_model' => 'test-model', 'enabled' => true]);
            Http::fake(['api.example.test/*' => Http::response($type === 'google' ? ['candidates' => [['content' => ['parts' => [['text' => 'OK']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['totalTokenCount' => 5]] : ['content' => [['type' => 'text', 'text' => 'OK']], 'usage' => ['input_tokens' => 3, 'output_tokens' => 2]], 200)]);
            $p = $this->postJson('/api/projects', ['name' => $type, 'instructions' => 'Retain this instruction.'])->json('data.id');
            $c = $this->postJson('/api/conversations', ['project_id' => $p])->json('data.id');
            $run = $this->postJson('/api/conversations/'.$c.'/runs', ['request_id' => (string) Str::uuid(), 'message' => 'Hi', 'mode' => 'chat'])->assertStatus(202)->json('data');
            app(Executor::class)->execute($run['id']);
            $this->getJson('/api/runs/'.$run['id'])->assertJsonPath('data.content', 'OK')->assertJsonPath('data.status', 'completed');
            Http::assertSent(fn ($r) => str_contains($type === 'google' ? $r['systemInstruction']['parts'][0]['text'] : $r['system'],'Retain this instruction.'));
        }
    }
}

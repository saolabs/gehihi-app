<?php

namespace Tests\Feature;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiRuntime\Models\GenerationRun;
use App\Modules\AiRuntime\Services\Executor;
use App\Modules\AiRuntime\Services\ModelFlows;
use App\Modules\Identity\Models\User;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModelFlowsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['gehihi.egress_allowlist' => ['api.example.test', 'search.example.test', 'source.example.test']]);
    }

    private function user(bool $admin = false): User
    {
        return User::create(['name' => 'Flow user', 'email' => Str::uuid().'@example.test', 'password' => 'test-password', 'is_admin' => $admin]);
    }

    private function provider(?string $owner = null): Provider
    {
        return Provider::create(['name' => 'Flow AI', 'type' => 'openai_compatible', 'kind' => 'llm', 'base_url' => 'https://api.example.test/v1', 'models' => ['chat', 'planner', 'assessor', 'writer', 'backup'], 'default_model' => 'chat', 'enabled' => true, 'user_id' => $owner]);
    }

    private function selection(Provider $p, string $model): array
    {
        return ['provider_id' => $p->id, 'model_id' => $model];
    }

    private function start(string $mode = 'deep', ?array $selection = null): array
    {
        $c = $this->postJson('/api/conversations')->assertCreated()->json('data.id');
        return $this->postJson('/api/conversations/'.$c.'/runs', ['request_id' => (string) Str::uuid(), 'message' => 'Compare source evidence.', 'mode' => $mode, ...($selection ? ['selection' => $selection] : [])])->assertStatus(202)->json('data');
    }

    public function test_admin_workflow_accepts_only_available_system_models_and_validates_each_stage(): void
    {
        $this->getJson('/admin/model-flows')->assertUnauthorized();
        $user = $this->user();
        $this->actingAs($user)->getJson('/admin/model-flows')->assertForbidden();
        $system = $this->provider();
        $private = $this->provider($user->id);
        $this->actingAs($this->user(true))->getJson('/admin/model-flows')->assertOk()->assertJsonCount(3, 'data.stages')->assertJsonCount(5, 'data.options');
        $flows = ['plan' => [...$this->selection($system, 'planner'), 'fallbacks' => [$this->selection($system, 'backup')]], 'assess' => null, 'report' => null];
        $this->putJson('/admin/model-flows', ['flows' => $flows])->assertOk()->assertJsonPath('data.stages.0.value', $system->id.'|planner');
        $this->putJson('/admin/model-flows', ['flows' => [...$flows, 'plan' => $this->selection($private, 'planner')]])->assertUnprocessable();
        $this->putJson('/admin/model-flows', ['flows' => [...$flows, 'plan' => $this->selection($system, 'not-allowed')]])->assertUnprocessable();
        $this->putJson('/admin/model-flows', ['flows' => ['plan' => null]])->assertUnprocessable();
        $this->putJson('/admin/model-flows', ['flows' => [...$flows, 'unknown' => null]])->assertUnprocessable();
        $system->update(['enabled' => false]);
        $this->getJson('/admin/model-flows')->assertJsonPath('data.stages.0.unavailable', true)->assertJsonPath('data.stages.0.fallback_unavailable', true);
        $this->putJson('/admin/model-flows', ['flows' => array_fill_keys(['plan', 'assess', 'report'], null)])->assertOk();
        $this->withHeaders(['Accept' => 'text/html'])->get('/admin/model-flows')->assertOk()->assertSee('Luồng model');
    }

    public function test_workspace_overrides_are_private_and_preserve_other_preferences(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $mine = $this->provider($user->id);
        $other = $this->provider($this->user()->id);
        $ws = $this->getJson('/api/bootstrap')->json('data.workspace.id');
        $this->putJson('/api/preferences', ['theme' => 'dark', 'locale' => 'en'])->assertOk();
        $flows = ['plan' => $this->selection($mine, 'planner'), 'assess' => null, 'report' => null];
        $this->putJson('/api/model-flows', ['flows' => $flows])->assertOk();
        $this->putJson('/api/preferences', ['theme' => 'light'])->assertOk();
        $pref = Workspace::findOrFail($ws)->preferences;
        $this->assertSame('en', $pref['locale']);
        $this->assertSame($mine->id, $pref['model_flows']['plan']['provider_id']);
        $this->getJson('/api/model-flows')->assertJsonCount(5, 'data.options')->assertJsonPath('data.stages.0.value', $mine->id.'|planner');
        $this->putJson('/api/model-flows', ['flows' => [...$flows, 'report' => $this->selection($other, 'writer')]])->assertUnprocessable();
        $this->assertNull(app(ModelFlows::class)->defaults()['plan']);
    }

    public function test_run_freezes_stage_models_with_workspace_then_system_then_chat_inheritance(): void
    {
        $p = $this->provider();
        app(ModelFlows::class)->save(['plan' => $this->selection($p, 'planner'), 'assess' => null, 'report' => $this->selection($p, 'writer')]);
        $this->putJson('/api/model-flows', ['flows' => ['plan' => $this->selection($p, 'backup'), 'assess' => null, 'report' => null]])->assertOk();
        $run = $this->start();
        $snapshot = GenerationRun::findOrFail($run['id'])->snapshot;
        $this->assertSame('backup', $snapshot['stage_candidates']['plan'][0]['model_id']);
        $this->assertSame('chat', $snapshot['stage_candidates']['assess'][0]['model_id']);
        $this->assertSame('writer', $snapshot['stage_candidates']['report'][0]['model_id']);
        app(ModelFlows::class)->save(['plan' => null, 'assess' => null, 'report' => null]);
        $this->assertSame($snapshot, GenerationRun::findOrFail($run['id'])->snapshot);
        $this->postJson('/api/runs/'.$run['id'].'/cancel')->assertOk();
        $chat = $this->start('chat');
        $this->assertArrayNotHasKey('stage_candidates', GenerationRun::findOrFail($chat['id'])->snapshot);
    }

    public function test_execution_uses_the_configured_model_for_each_step_and_only_its_selected_fallback(): void
    {
        $p = $this->provider();
        Provider::create(['name' => 'Web', 'type' => 'searxng', 'kind' => 'search', 'base_url' => 'https://search.example.test', 'models' => [], 'enabled' => true]);
        app(ModelFlows::class)->save(['plan' => $this->selection($p, 'planner'), 'assess' => $this->selection($p, 'assessor'),
            'report' => [...$this->selection($p, 'writer'), 'fallbacks' => [$this->selection($p, 'backup')]]]);
        $models = [];
        Http::fake(function ($r) use (&$models) {
            if (str_contains($r->url(), 'search.example.test')) return Http::response(['results' => [['url' => 'https://source.example.test/a', 'title' => 'Evidence', 'content' => 'Snippet']]]);
            if (str_contains($r->url(), 'source.example.test')) return Http::response('<html><main>'.str_repeat('Reliable source evidence. ', 50).'</main></html>', 200, ['Content-Type' => 'text/html']);
            $models[] = $r['model'];
            if ($r['model'] === 'writer') return Http::response([], 503);
            $text = match ($r['model']) {
                'planner' => '{"questions":["Compare"],"queries":["public evidence"]}',
                'assessor' => '{"complete":true,"missing":[],"queries":[]}',
                'backup' => 'Source-supported answer [S1].',
                default => throw new \RuntimeException('Unexpected model '.$r['model']),
            };
            return Http::response(['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 10]]);
        });
        $run = $this->start('quick');
        app(Executor::class)->execute($run['id']);
        $this->getJson('/api/runs/'.$run['id'])->assertJsonPath('data.status', 'completed')->assertJsonPath('data.actual_model.model_id', 'backup');
        $this->assertSame(['planner', 'assessor', 'writer', 'backup'], $models);
        $this->assertStringContainsString('Source-supported', $this->getJson('/api/runs/'.$run['id'])->json('data.content'));
    }

    public function test_removed_stage_model_is_rejected_before_queuing_or_adding_messages(): void
    {
        $p = $this->provider();
        app(ModelFlows::class)->save(['plan' => $this->selection($p, 'planner'), 'assess' => null, 'report' => null]);
        $p->update(['models' => ['chat']]);
        $c = $this->postJson('/api/conversations')->json('data.id');
        $this->postJson('/api/conversations/'.$c.'/runs', ['request_id' => (string) Str::uuid(), 'message' => 'Research', 'mode' => 'deep'])->assertUnprocessable();
        $this->assertDatabaseCount('generation_runs', 0);
        $this->getJson('/api/conversations/'.$c)->assertJsonCount(0, 'data.messages');
        Queue::assertNothingPushed();
    }
}

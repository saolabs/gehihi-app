<?php

namespace Tests\Feature;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiProviders\Services\OutboundHttp;
use App\Modules\AiRuntime\Jobs\ExecuteRun;
use App\Modules\AiRuntime\Models\GenerationRun;
use App\Modules\AiRuntime\Repositories\RunRepository;
use App\Modules\AiRuntime\Services\Executor;
use App\Modules\AiRuntime\Services\RunStopped;
use App\Modules\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['gehihi.egress_allowlist' => ['api.example.test', 'api.openai.com', 'search.example.test', 'source.example.test']]);
        Storage::fake('local');
    }

    private function provider(?string $user = null, string $type = 'openai_compatible', array $keys = []): Provider
    {
        return Provider::create(['name' => 'Test provider', 'user_id' => $user, 'kind' => 'llm', 'type' => $type, 'base_url' => 'https://api.example.test/v1', 'secrets' => $keys, 'models' => ['test-model'], 'default_model' => 'test-model', 'enabled' => true, 'is_default' => true]);
    }

    private function conversation(?string $project = null): string
    {
        return $this->postJson('/api/conversations', ['project_id' => $project])->assertCreated()->json('data.id');
    }

    private function start(string $c, array $extra = []): array
    {
        return $this->postJson('/api/conversations/'.$c.'/runs', ['request_id' => (string) Str::uuid(), 'message' => 'Xin chào', 'mode' => 'chat', ...$extra])->assertStatus(202)->json('data');
    }

    private function response(string $text = 'Xin chào!')
    {
        return Http::response(['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 12]], 200);
    }

    public function test_guest_can_create_project_and_private_conversations_without_an_account(): void
    {
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('data.workspace.guest', true);
        $project = $this->postJson('/api/projects', ['name' => 'Nghiên cứu', 'instructions' => 'Trả lời tiếng Việt'])->assertOk()->json('data');
        $c = $this->conversation($project['id']);
        $this->getJson('/api/conversations/'.$c)->assertOk()->assertJsonPath('data.project.instructions', 'Trả lời tiếng Việt');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('instruction_revisions', ['project_id' => $project['id'], 'revision' => 1]);
        $this->postJson('/api/projects', ['name' => 'Dự án trống', 'instructions' => ''])->assertOk();
    }

    public function test_guests_are_isolated_for_project_conversation_file_and_run(): void
    {
        $this->provider();
        $project = $this->postJson('/api/projects', ['name' => 'Riêng tư', 'instructions' => 'Bí mật'])->json('data');
        $c = $this->conversation($project['id']);
        $run = $this->start($c);
        $file = $this->postJson('/api/files', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'Private notes')])->assertCreated()->json('data.id');
        $this->withSession(['guest_token' => 'another-guest']);
        $this->getJson('/api/conversations/'.$c)->assertNotFound();
        $this->patchJson('/api/projects/'.$project['id'], ['name' => 'Hack'])->assertNotFound();
        $this->getJson('/api/runs/'.$run['id'])->assertNotFound();
        $this->getJson('/api/files/'.$file.'/download')->assertNotFound();
    }

    public function test_instruction_snapshot_is_preserved_during_run_and_refreshed_next_turn(): void
    {
        $this->provider();
        Http::fake(['api.example.test/*' => fn () => $this->response()]);
        $p = $this->postJson('/api/projects', ['name' => 'A', 'instructions' => 'Luôn dùng đơn vị VND'])->json('data.id');
        $c = $this->conversation($p);
        $run = $this->start($c);
        $this->patchJson('/api/projects/'.$p, ['instructions' => 'Use English and USD'])->assertOk();
        app(Executor::class)->execute($run['id']);
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'VND') && ! str_contains($r['messages'][0]['content'], 'USD'));
        $first = GenerationRun::findOrFail($run['id']);
        $this->assertSame('completed', $first->status);
        $this->assertSame(1, $first->snapshot['instruction_revision']);
        $next = $this->start($c, ['message' => 'Tiếp tục']);
        $this->assertSame(2, GenerationRun::findOrFail($next['id'])->snapshot['instruction_revision']);
        $this->assertStringContainsString('USD', GenerationRun::findOrFail($next['id'])->snapshot['system']);
    }

    public function test_duplicate_send_is_idempotent_and_does_not_create_or_charge_twice(): void
    {
        $this->provider();
        $c = $this->conversation();
        $key = (string) Str::uuid();
        $first = $this->start($c, ['request_id' => $key]);
        $second = $this->start($c, ['request_id' => $key]);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('generation_runs', 1);
        Queue::assertPushed(ExecuteRun::class, fn ($job) => $job->runId === $first['id']);
    }

    public function test_edit_and_regenerate_preserve_message_branches(): void
    {
        $this->provider();
        Http::fake(['api.example.test/*' => fn () => $this->response()]);
        $c = $this->conversation();
        $first = $this->start($c);
        app(Executor::class)->execute($first['id']);
        $second = $this->start($c, ['regenerate_id' => $first['message_id']]);
        app(Executor::class)->execute($second['id']);
        $detail = $this->getJson('/api/conversations/'.$c)->json('data');
        $this->assertCount(3, $detail['messages']);
        $assistant = array_values(array_filter($detail['messages'], fn ($m) => $m['role'] === 'assistant'));
        $this->assertSame($assistant[0]['parent_id'], $assistant[1]['parent_id']);
        $third = $this->start($c, ['parent_id' => null, 'message' => 'Câu hỏi đã sửa']);
        $messages = $this->getJson('/api/conversations/'.$c)->json('data.messages');
        $lastUser = collect($messages)->firstWhere('content', 'Câu hỏi đã sửa');
        $this->assertNull($lastUser['parent_id']);
    }

    public function test_provider_keys_are_encrypted_hidden_and_owner_scoped(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'some-password-123']);
        $this->actingAs($user);
        $data = ['name' => 'Cá nhân', 'type' => 'openai_compatible', 'base_url' => 'https://api.example.test/v1', 'api_keys' => "secret-one\nsecret-two", 'models' => "test-model\nsecond-model", 'enabled' => true];
        $created = $this->postJson('/api/providers', $data)->assertOk();
        $id = $created->json('data.id');
        $this->assertStringNotContainsString('secret-one', $created->getContent());
        $this->assertSame(['test-model', 'second-model'], $created->json('data.models'));
        $this->assertStringNotContainsString('secret-one', DB::table('providers')->where('id', $id)->value('secrets'));
        $this->patchJson('/api/providers/'.$id, [...$data, 'api_keys' => ''])->assertOk();
        $this->assertSame(['secret-one', 'secret-two'], Provider::findOrFail($id)->secrets);
        $other = User::create(['name' => 'B', 'email' => 'b@example.test', 'password' => 'some-password-456']);
        $this->actingAs($other);
        $this->deleteJson('/api/providers/'.$id)->assertNotFound();
        $c = $this->conversation();
        $this->postJson('/api/conversations/'.$c.'/runs', ['request_id' => (string) Str::uuid(), 'message' => 'Hi', 'mode' => 'chat', 'selection' => ['provider_id' => $id, 'model_id' => 'test-model']])->assertNotFound();
    }

    public function test_guest_cannot_manage_providers_and_normal_user_cannot_manage_system_pool(): void
    {
        $this->getJson('/api/providers')->assertUnauthorized();
        $u = User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'some-password-123']);
        $this->actingAs($u)->getJson('/api/providers?system=1')->assertForbidden();
    }

    public function test_key_rotation_honours_cooldown_and_records_usage(): void
    {
        $this->provider(null, 'openai', ['bad-key', 'good-key']);
        Http::fake(['api.openai.com/*' => Http::sequence()->push(['error' => ['message' => 'limited']], 429)->push(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 8]])]);
        $run = $this->start($this->conversation());
        app(Executor::class)->execute($run['id']);
        $this->getJson('/api/runs/'.$run['id'])->assertJsonPath('data.status', 'completed')->assertJsonPath('data.content', 'OK')->assertJsonPath('data.used_tokens', 8);
        $this->assertDatabaseCount('provider_key_health', 2);
        Http::assertSentCount(2);
        $this->assertSame('reported', DB::table('run_operations')->where('kind', 'llm')->value('metering'));
    }

    public function test_stream_is_persisted_and_incomplete_stream_is_not_claimed_complete(): void
    {
        $this->provider();
        $data = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Một phần']]]])."\n\n";
        Http::fake(['api.example.test/*' => Http::response($data, 200, ['Content-Type' => 'text/event-stream'])]);
        $run = $this->start($this->conversation());
        app(Executor::class)->execute($run['id']);
        $this->getJson('/api/runs/'.$run['id'])->assertJsonPath('data.status', 'failed')->assertJsonPath('data.content', 'Một phần');
    }

    public function test_cancelled_job_does_not_call_provider(): void
    {
        $this->provider();
        $r = $this->start($this->conversation());
        $this->postJson('/api/runs/'.$r['id'].'/cancel')->assertOk();
        app(Executor::class)->execute($r['id']);
        Http::assertNothingSent();
        $this->getJson('/api/runs/'.$r['id'])->assertJsonPath('data.status', 'cancelled');
    }

    public function test_old_worker_cannot_write_or_finish_a_resumed_run(): void
    {
        $this->provider();
        $run = $this->start($this->conversation(), ['mode' => 'deep']);
        $old = new RunRepository;
        $http = new RunRepository;
        $this->assertTrue($old->claim($run['id']));
        $http->finish($run['id'], 'cancelled');
        DB::transaction(fn () => $http->resume($run['workspace_id'], $run['id']));
        $this->assertTrue($http->claim($run['id']));
        $http->content($run['id'], 'New worker result');
        $old->finish($run['id'], 'cancelled');
        $this->assertFalse($old->active($run['id']));
        try {
            $old->content($run['id'], 'Stale result');
            $this->fail('Stale worker wrote content');
        } catch (RunStopped) {
        }
        $this->getJson('/api/runs/'.$run['id'])->assertJsonPath('data.status', 'running')->assertJsonPath('data.content', 'New worker result');
        (new ExecuteRun($run['id'], 0))->failed(new \RuntimeException('Old job failed'));
        $this->getJson('/api/runs/'.$run['id'])->assertJsonPath('data.status', 'running');
    }

    public function test_model_capabilities_are_checked_per_model_and_selection_is_retained(): void
    {
        $provider = $this->provider();
        $provider->update(['models' => ['text', 'reasoner'], 'default_model' => 'text', 'capabilities' => ['models' => ['text' => ['reasoning' => false], 'reasoner' => ['reasoning' => true]]]]);
        $c = $this->conversation();
        $this->postJson('/api/conversations/'.$c.'/runs', ['request_id' => (string) Str::uuid(), 'mode' => 'chat', 'message' => 'Hi', 'thinking' => 'reasoning', 'selection' => ['provider_id' => $provider->id, 'model_id' => 'text']])->assertStatus(422);
        $this->assertDatabaseCount('messages', 0);
        $run = $this->start($c, ['thinking' => 'reasoning', 'selection' => ['provider_id' => $provider->id, 'model_id' => 'reasoner']]);
        $this->getJson('/api/conversations/'.$c)->assertJsonPath('data.selection.model_id', 'reasoner');
        $this->assertSame('reasoner', GenerationRun::findOrFail($run['id'])->snapshot['candidates'][0]['model_id']);
    }

    public function test_image_payload_is_not_mistaken_for_millions_of_text_tokens(): void
    {
        $p = $this->provider();
        $p->update(['capabilities' => ['models' => ['test-model' => ['vision' => true]]]]);
        Http::fake(['api.example.test/*' => fn () => $this->response('Image received')]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1cAAAAASUVORK5CYII=');
        $file = $this->postJson('/api/files', ['file' => UploadedFile::fake()->createWithContent('pixel.png', $png)])->assertCreated()->assertJsonPath('data.status', 'ready')->json('data.id');
        $run = $this->start($this->conversation(), ['file_ids' => [$file]]);
        app(Executor::class)->execute($run['id']);
        $this->getJson('/api/runs/'.$run['id'])->assertJsonPath('data.status', 'completed')->assertJsonPath('data.content', 'Image received');
        Http::assertSent(fn ($request) => $request['messages'][1]['content'][1]['type'] === 'image_url');
    }

    public function test_search_provider_connection_can_be_tested_without_saving_it(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'probe@example.test', 'password' => 'long-test-password']);
        $this->actingAs($user);
        Http::fake(['search.example.test/*' => Http::response(['results' => []])]);
        $response = $this->postJson('/api/providers/test', ['name' => 'Search', 'type' => 'searxng', 'base_url' => 'https://search.example.test', 'models' => ''])->assertOk()->assertJsonPath('data.results.0.ok', true);
        $this->assertDatabaseCount('providers', 0);
        Http::assertSentCount(1);
    }

    public function test_guest_import_is_idempotent_and_user_does_not_automatically_become_admin(): void
    {
        $p = $this->postJson('/api/projects', ['name' => 'Guest work', 'instructions' => 'Giữ ngữ cảnh'])->json('data.id');
        $c = $this->conversation($p);
        $this->postJson('/api/auth/register', ['name' => 'New user', 'email' => 'new@example.test', 'password' => 'a-long-password-123'])->assertCreated()->assertJsonPath('data.user.is_admin', false);
        $this->postJson('/api/auth/import')->assertOk();
        $this->postJson('/api/auth/import')->assertOk();
        $this->getJson('/api/conversations/'.$c)->assertOk();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_upload_does_not_claim_binary_files_were_read(): void
    {
        $this->postJson('/api/files', ['file' => UploadedFile::fake()->createWithContent('data.txt', "\0binary")])->assertCreated()->assertJsonPath('data.status', 'error')->assertJsonMissingPath('data.path');
        $this->postJson('/api/files', ['file' => UploadedFile::fake()->createWithContent('notes.md', '# Notes')])->assertCreated()->assertJsonPath('data.status', 'ready');
    }

    public function test_quota_is_enforced_before_dispatch_or_message_creation(): void
    {
        config(['gehihi.daily_runs' => 1]);
        $this->provider();
        $c = $this->conversation();
        $this->start($c);
        $this->postJson('/api/conversations/'.$c.'/runs', ['request_id' => (string) Str::uuid(), 'message' => 'Another', 'mode' => 'chat'])->assertStatus(429);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_private_networks_and_credential_bearing_urls_are_blocked(): void
    {
        $http = app(OutboundHttp::class);
        foreach (['http://127.0.0.1/v1', 'https://127.0.0.1/v1', 'https://169.254.169.254/latest/meta-data', 'https://user:pass@example.com/v1'] as $url) {
            try {
                $http->options($url);
                $this->fail('Unsafe URL accepted');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_shell_renders_server_content_and_spa_contract(): void
    {
        $this->get('/')->assertOk()->assertSee('Gehihi')->assertSee('Hôm nay bạn muốn tìm hiểu gì')->assertSee('APP_CONFIGS',false)->assertSee('csrf-token',false);
    }
}

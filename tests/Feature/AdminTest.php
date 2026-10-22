<?php

namespace Tests\Feature;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiProviders\Repositories\ProviderRepositoryInterface;
use App\Modules\AiRuntime\Models\GenerationRun;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTest extends TestCase
{
    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => Str::random(16), 'is_admin' => true]);
    }

    private function seedRun(string $status, int $tokens, ?Provider $p = null): void
    {
        $user = User::create(['name' => 'U'.Str::random(4), 'email' => Str::random(8).'@example.test', 'password' => Str::random(16)]);
        DB::table('workspaces')->insert(['id' => $wsId = (string) Str::uuid(), 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => $c = (string) Str::uuid(), 'workspace_id' => $wsId, 'title' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => $m = (string) Str::uuid(), 'conversation_id' => $c, 'role' => 'assistant', 'created_at' => now(), 'updated_at' => now()]);
        GenerationRun::create(['workspace_id' => $wsId, 'conversation_id' => $c, 'message_id' => $m, 'request_id' => Str::random(8), 'mode' => 'chat', 'status' => $status, 'snapshot' => [], 'reserved_tokens' => 100, 'used_tokens' => $tokens, 'error' => $status === 'failed' ? 'Provider lỗi' : null, 'actual_model' => $p ? ['provider_id' => $p->id, 'provider_name' => $p->name, 'model_id' => 'm1'] : null]);
    }

    public function test_admin_endpoints_require_admin(): void
    {
        $this->getJson('/api/admin/overview')->assertUnauthorized();
        $user = User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => Str::random(16)]);
        $this->actingAs($user)->getJson('/api/admin/overview')->assertForbidden();
        $this->actingAs($user)->putJson('/api/admin/limits', [])->assertForbidden();
    }

    public function test_overview_and_provider_health_aggregate_runs(): void
    {
        $p = Provider::create(['name' => 'Pool', 'kind' => 'llm', 'type' => 'openai_compatible', 'base_url' => 'https://api.example.test/v1', 'secrets' => ['k1', 'k2'], 'models' => ['m1'], 'default_model' => 'm1']);
        $this->seedRun('completed', 300, $p);
        $this->seedRun('failed', 0, $p);
        app(ProviderRepositoryInterface::class)->cooldown($p, 'k1', 60);
        $admin = $this->admin();

        $data = $this->actingAs($admin)->getJson('/api/admin/overview?days=7')->assertOk()->json('data');
        $this->assertSame(2, $data['totals']['runs']);
        $this->assertSame(1, $data['totals']['failed']);
        $this->assertSame(300, $data['totals']['tokens']);
        $this->assertCount(7, $data['series']);
        $this->assertSame(2, end($data['series'])['runs']);
        $this->assertSame('Pool · m1', $data['models'][0]['name']);
        $this->assertSame('Provider lỗi', $data['failures'][0]['error']);

        $system = $this->actingAs($admin)->getJson('/api/admin/providers')->assertOk()->json('data.system.0');
        $this->assertSame([2, 1, 2, 1], [$system['key_count'], $system['cooling_keys'], $system['runs_7d'], $system['failed_7d']]);
        $this->assertArrayNotHasKey('secrets', $system);
    }

    public function test_users_list_and_admin_toggle_without_self_lockout(): void
    {
        $admin = $this->admin();
        $this->seedRun('completed', 50);
        $users = $this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->json('data.users');
        $this->assertCount(2, $users);
        $other = collect($users)->firstWhere('is_admin', false);
        $this->assertSame([1, 50], [$other['runs_30d'], $other['tokens_30d']]);

        $this->actingAs($admin)->patchJson('/api/admin/users/'.$other['id'], ['is_admin' => true])->assertOk();
        $this->assertTrue(User::find($other['id'])->is_admin);
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$admin->id, ['is_admin' => false])->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_admin);
        $this->assertCount(1, $this->actingAs($admin)->getJson('/api/admin/users?q=admin@')->json('data.users'));
    }

    public function test_limits_are_validated_saved_and_applied_to_config(): void
    {
        $admin = $this->admin();
        $limits = $this->actingAs($admin)->getJson('/api/admin/limits')->assertOk()->json('data.limits');
        $this->assertSame(config('gehihi.daily_runs'), $limits['daily_runs']);

        $this->actingAs($admin)->putJson('/api/admin/limits', ['limits' => [...$limits, 'daily_runs' => 0]])->assertUnprocessable();
        $saved = $this->actingAs($admin)->putJson('/api/admin/limits', ['limits' => [...$limits, 'daily_runs' => 7, 'research_deep_pages' => 20]])->assertOk()->json('data');
        $this->assertSame(7, $saved['limits']['daily_runs']);
        $this->assertSame(50, $saved['defaults']['daily_runs']);
        $this->assertSame(7, config('gehihi.daily_runs'));
        $this->assertSame(20, config('gehihi.research.deep.pages'));
        $this->assertSame(7, json_decode(DB::table('app_settings')->value('value'), true)['daily_runs']);
    }
}

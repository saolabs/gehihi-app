<?php

namespace Tests\Feature;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\Identity\Models\User;
use App\Modules\Research\Services\SearchGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchServicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['gehihi.egress_allowlist' => ['api.tavily.com', 'google.serper.dev', 'api.example.test', 'search.example.test']]);
        Cache::flush();
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'search-admin@example.test', 'password' => 'test-password', 'is_admin' => true]);
    }

    private function search(string $name, array $keys, ?string $owner = null): Provider
    {
        return Provider::create(['name' => $name, 'type' => 'tavily', 'kind' => 'search', 'base_url' => '', 'secrets' => $keys, 'models' => [], 'default_model' => '', 'enabled' => true, 'is_default' => false, 'user_id' => $owner]);
    }

    public function test_search_settings_are_separate_from_ai_and_require_admin(): void
    {
        $this->getJson('/admin/search-services')->assertUnauthorized();
        $admin = $this->admin();
        $user = User::create(['name' => 'User', 'email' => 'search-user@example.test', 'password' => 'test-password']);
        $this->actingAs($user)->getJson('/admin/search-services')->assertForbidden();
        $search = $this->search('Web search account', ['private-search-key']);
        $ai = Provider::create(['name' => 'AI connection', 'type' => 'openai_compatible', 'kind' => 'llm', 'base_url' => 'https://api.example.test/v1', 'models' => ['model'], 'secrets' => [], 'default_model' => 'model']);
        $this->actingAs($admin)->getJson('/admin/providers')->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $ai->id);
        $groups = $this->getJson('/admin/search-services')->assertOk()->assertDontSee('private-search-key')->json('data.groups');
        $this->assertCount(6, $groups);
        $this->assertSame($search->id, collect($groups)->firstWhere('type', 'tavily')['accounts'][0]['id']);
        $types = $this->getJson('/admin/providers/create')->assertOk()->json('data.types');
        $this->assertTrue(collect($types)->every(fn ($type) => $type['kind'] === 'llm'));
        $this->withHeaders(['Accept' => 'text/html'])->get('/admin/search-services')->assertOk()->assertSee('Tavily')->assertSee('Dịch vụ tìm kiếm');
        $this->get('/admin/providers/'.$search->id)->assertRedirect('/admin/search-services?edit='.$search->id);
    }

    public function test_multiple_search_accounts_and_keys_remain_encrypted_and_editable(): void
    {
        $this->actingAs($this->admin());
        $form = ['name' => 'Tavily account A', 'type' => 'tavily', 'api_keys' => "account-a-key1\naccount-a-key2", 'enabled' => true];
        $this->postJson('/admin/search-services', $form)->assertCreated()->assertDontSee('account-a-key1');
        $this->postJson('/admin/search-services', [...$form, 'name' => 'Tavily account B', 'api_keys' => 'account-b-key'])->assertCreated();
        $p = Provider::where('name', 'Tavily account A')->firstOrFail();
        $this->assertSame(['account-a-key1', 'account-a-key2'], $p->secrets);
        $this->assertStringNotContainsString('account-a-key1', $p->getRawOriginal('secrets'));
        $group = collect($this->getJson('/admin/search-services')->json('data.groups'))->firstWhere('type', 'tavily');
        $this->assertCount(2, $group['accounts']);
        $this->assertSame(3, $group['key_count']);
        $this->getJson('/admin/search-services/'.$p->id)->assertOk()->assertJsonPath('data.account.key_count', 2)->assertDontSee('account-a-key1');
        $this->putJson('/admin/search-services/'.$p->id, [...$form, 'api_keys' => '', 'name' => 'Renamed'])->assertOk();
        $this->assertSame(['account-a-key1', 'account-a-key2'], $p->fresh()->secrets);
        $this->postJson('/admin/search-services', [...$form, 'type' => 'google'])->assertUnprocessable();
        $this->postJson('/admin/providers', $form)->assertUnprocessable();
        $this->deleteJson('/admin/search-services/'.$p->id)->assertOk();
        $this->assertSoftDeleted($p);
    }

    public function test_search_pages_do_not_expose_or_modify_private_accounts_or_ai_connections(): void
    {
        $admin = $this->admin();
        $private = $this->search('Private account', ['secret'], $admin->id);
        $ai = Provider::create(['name' => 'AI', 'kind' => 'llm', 'type' => 'openai_compatible', 'base_url' => 'https://api.example.test/v1', 'models' => ['m'], 'default_model' => 'm', 'secrets' => []]);
        $this->actingAs($admin);
        foreach ([$private, $ai] as $p) {
            $this->getJson('/admin/search-services/'.$p->id)->assertNotFound();
            $this->deleteJson('/admin/search-services/'.$p->id)->assertNotFound();
            $this->postJson('/admin/search-services/test', ['id' => $p->id])->assertNotFound();
        }
    }

    public function test_search_rotates_accounts_and_keys_within_the_selected_service(): void
    {
        $a = $this->search('Account A', ['a1', 'a2']);
        $this->search('Account B', ['b1']);
        $owner = $this->admin();
        $this->search('Private account', ['never-sent'], $owner->id);
        Http::fake(['api.tavily.com/*' => fn () => Http::response(['results' => [['url' => 'https://example.com/result', 'title' => 'Result']]])]);
        $gateway = app(SearchGateway::class);
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($gateway->available(null, $a->id)); // availability must not advance rotation
            $this->assertCount(1, $gateway->search('query', null, ['search_provider_id' => $a->id], fn () => null)['hits']);
        }
        $headers = Http::recorded()->map(fn ($pair) => $pair[0]->header('Authorization')[0])->all();
        $this->assertSame(['Bearer a1', 'Bearer b1', 'Bearer a2'], $headers);
    }

    public function test_search_falls_back_to_another_account_and_cools_down_a_rejected_key(): void
    {
        $a = $this->search('Account A', ['rejected', 'rejected-2', 'rejected-3', 'rejected-4']);
        $this->search('Account B', ['working']);
        Http::fake(['api.tavily.com/*' => fn ($r) => $r->hasHeader('Authorization', 'Bearer working')
            ? Http::response(['results' => [['url' => 'https://example.com/result', 'title' => 'Result']]])
            : Http::response([], 429)]);
        $result = app(SearchGateway::class)->search('query', null, ['search_provider_id' => $a->id], fn () => null);
        $this->assertCount(1, $result['hits']);
        $this->assertSame(['unavailable', 'ok'], array_column($result['attempts'], 'status'));
        Http::assertSentCount(2);
        $this->assertNotContains('rejected', app(\App\Modules\AiProviders\Repositories\ProviderRepositoryInterface::class)->keys($a));
    }
}

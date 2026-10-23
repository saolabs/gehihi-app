<?php

namespace App\Modules\Research\Services;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiProviders\Repositories\ProviderRepositoryInterface;
use App\Modules\AiProviders\Services\OutboundHttp;
use App\Modules\AiProviders\Services\ProviderCatalog;
use App\Modules\AiProviders\Services\ProviderFailure;
use App\Modules\AiProviders\Services\ProviderService;
use App\Modules\AiRuntime\Services\RunStopped;
use Illuminate\Support\Facades\Cache;

class SearchGateway
{
    public function __construct(private OutboundHttp $http, private ProviderService $providers, private ProviderRepositoryInterface $repo) {}

    public function available(?string $userId, ?string $selected = null): bool
    {
        return count($this->pool($userId, $selected)) > 0;
    }

    private function pool(?string $userId, ?string $selected): array
    {
        $pool = array_values(array_filter($this->repo->visible($userId), fn ($p) => $p->kind === 'search'));
        if (!$selected) return $pool;
        $service = $this->providers->allowed($selected, $userId, 'search');
        return array_values(array_filter($pool, fn ($p) => $p->type === $service->type && $p->user_id === $service->user_id));
    }

    /** Rotate accounts within a service/scope while retaining service priority. */
    private function rotateAccounts(array $pool): array
    {
        $groups = [];
        foreach ($pool as $provider) $groups[$provider->type.':'.($provider->user_id ?? 'system')][] = $provider;
        $ordered = [];
        foreach ($groups as $accounts) {
            if (count($accounts) > 1) {
                $key = 'search-account-cursor:'.hash('sha256', implode(':', array_map(fn ($p) => $p->id, $accounts)));
                $cursor = Cache::lock($key.':lock', 5)->block(3, function () use ($key) {
                    $cursor = (int) Cache::get($key, 0);
                    Cache::put($key, $cursor + 1, now()->addDay());
                    return $cursor;
                });
                $offset = $cursor % count($accounts);
                $accounts = [...array_slice($accounts, $offset), ...array_slice($accounts, 0, $offset)];
            }
            array_push($ordered, ...$accounts);
        }
        return $ordered;
    }

    /** Give each account a turn before trying its next key. */
    private function keyAttempts(array $pool): \Generator
    {
        $keys = [];
        for ($round = 0; $round < 20; $round++) {
            $available = false;
            foreach ($pool as $index => $provider) {
                if ($round === 0) $keys[$index] = $this->repo->keys($provider);
                if (!array_key_exists($round, $keys[$index])) continue;
                $available = true;
                yield [$provider, $keys[$index][$round]];
            }
            if (!$available) break;
        }
    }

    public function probe(Provider $provider): string
    {
        $result = $this->search('Saola', null, [], fn () => null, [$provider]);
        if (! collect($result['attempts'])->contains(fn ($attempt) => $attempt['status'] === 'ok')) {
            throw new ProviderFailure('Chưa kết nối được dịch vụ tìm kiếm. Kiểm tra key, URL và quyền truy cập.');
        }

        return 'Kết nối tìm kiếm thành công · '.count($result['hits']).' kết quả';
    }

    public function search(string $query, ?string $userId, array $options, callable $guard, ?array $pool = null): array
    {
        $pool ??= $this->pool($userId, $options['search_provider_id'] ?? null);
        $pool = $this->rotateAccounts($pool);
        if (! $pool) {
            throw new ProviderFailure('Chưa có provider tìm kiếm. Thêm trong Cài đặt hoặc nhờ quản trị cấu hình.');
        }
        $attempts = [];
        $requests = 0;
        foreach ($this->keyAttempts($pool) as [$p, $key]) {
            $guard();
            if (++$requests > 4) {
                return ['hits' => [], 'attempts' => $attempts];
            }$headers = ['Accept' => 'application/json'];
            $base = ProviderCatalog::baseUrl($p->type, $p->base_url);
            $language = $options['language'] ?? 'vi';
            $country = $options['country'] ?? 'vn';
            [$method,$url,$body] = match ($p->type) {
                'searxng' => ['GET', $base.'/search?'.http_build_query(['q' => $query, 'format' => 'json', 'language' => $language, 'categories' => 'general']), []],
                'tavily' => ['POST', $base.'/search', ['query' => $query, 'max_results' => 6, 'search_depth' => 'basic']],
                'serper' => ['POST', $base.'/search', ['q' => $query, 'num' => 6, 'gl' => $country, 'hl' => $language]],
                'serpapi' => ['GET', $base.'/search.json?'.http_build_query(['q' => $query, 'engine' => 'google', 'api_key' => $key, 'num' => 6, 'gl' => $country, 'hl' => $language]), []],
                'brave' => ['GET', $base.'/web/search?'.http_build_query(['q' => $query, 'count' => 6, 'country' => strtoupper($country), 'search_lang' => $language]), []],
                'exa' => ['POST', $base.'/search', ['query' => $query, 'numResults' => 6, 'type' => 'auto']],
                default => throw new ProviderFailure('Adapter tìm kiếm chưa hỗ trợ.'),
            };
            $headers += match ($p->type) {
                'serper' => ['X-API-KEY' => $key],'brave' => ['X-Subscription-Token' => $key],'exa' => ['x-api-key' => $key], 'tavily' => ['Authorization' => 'Bearer '.$key],default => []
            };
            try {
                $r = $this->http->send($method, $url, $headers, $body, 25);
                if (! $r->successful()) {
                    if (in_array($r->status(), [401, 403, 429])) {
                        $this->repo->cooldown($p, $key, 60);
                    }throw new ProviderFailure('Nguồn tìm kiếm trả HTTP '.$r->status(), $r->status());
                }
                $data = $this->http->json($r);
                $rows = match ($p->type) {
                    'serper' => $data['organic'] ?? [],'serpapi' => $data['organic_results'] ?? [],'brave' => $data['web']['results'] ?? [],default => $data['results'] ?? []
                };
                $hits = [];
                foreach ($rows as $row) {
                    $link = $row['url'] ?? $row['link'] ?? '';
                    $host = strtolower(parse_url($link, PHP_URL_HOST) ?? '');
                    if (! in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https']) || ! $host) {
                        continue;
                    }
                    $allowed = $options['include_domains'] ?? [];
                    $blocked = $options['exclude_domains'] ?? [];
                    $matches = fn ($domains) => collect($domains)->contains(fn ($d) => $host === $d || str_ends_with($host, '.'.$d));
                    if (($allowed && ! $matches($allowed)) || $matches($blocked)) {
                        continue;
                    }
                    $hits[] = ['url' => explode('#', $link)[0], 'title' => mb_substr($row['title'] ?? $link, 0, 250), 'text' => mb_substr($row['content'] ?? $row['snippet'] ?? $row['description'] ?? $row['text'] ?? '', 0, 3000), 'kind' => 'search_snippet', 'provider' => $p->type, 'observed_at' => now()->toIso8601String(), 'published_at' => $row['publishedDate'] ?? null];
                }
                $attempts[] = ['provider' => $p->name, 'status' => 'ok', 'results' => count($hits)];
                if ($hits) {
                    return ['hits' => array_slice($hits, 0, 6), 'attempts' => $attempts];
                }
            } catch (RunStopped $e) {
                throw $e;
            } catch (\Throwable $e) {
                $attempts[] = ['provider' => $p->name, 'status' => 'unavailable'];
            }
        }

        return ['hits' => [], 'attempts' => $attempts];
    }
}

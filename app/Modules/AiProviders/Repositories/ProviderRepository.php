<?php

namespace App\Modules\AiProviders\Repositories;

use App\Modules\AiProviders\Models\Provider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProviderRepository implements ProviderRepositoryInterface
{
    public function visible(?string $userId): array
    {
        return Provider::where('enabled', true)->where(fn ($q) => $q->whereNull('user_id')->when($userId, fn ($q) => $q->orWhere('user_id', $userId)))->orderByDesc('is_default')->oldest()->get()->all();
    }

    public function managed(?string $userId): array
    {
        return Provider::where('user_id', $userId)->latest()->get()->all();
    }

    public function find(string $id): Provider
    {
        return Provider::findOrFail($id);
    }

    public function save(?string $id, ?string $owner, array $data): Provider
    {
        return DB::transaction(function () use ($id, $owner, $data) {
            $p = $id ? Provider::where('user_id', $owner)->findOrFail($id) : new Provider(['user_id' => $owner]);
            if ($data['is_default'] ?? false) {
                Provider::where('user_id', $owner)->where('kind', $data['kind'])->update(['is_default' => false]);
            }
            $p->fill($data);
            $p->save();

            return $p;
        });
    }

    public function remove(string $id, ?string $owner): void
    {
        Provider::where('user_id', $owner)->findOrFail($id)->delete();
    }

    public function tested(string $id, bool $ok): void
    {
        Provider::whereKey($id)->update(['tested_at' => now(), 'test_status' => $ok ? 'ok' : 'error']);
    }

    public function keys(Provider $provider, bool $probe = false): array
    {
        $keys = $provider->secrets ?? [];
        if (! $keys) {
            return [null];
        }
        if ($probe || ! $provider->exists) {
            return $keys;
        }

        return Cache::lock('keys-'.$provider->id, 5)->block(3, function () use ($provider, $keys) {
            $row = DB::table('provider_key_health')->where('fingerprint', 'cursor:'.$provider->id)->first();
            $cursor = $row?->cursor ?? 0;
            DB::table('provider_key_health')->updateOrInsert(['fingerprint' => 'cursor:'.$provider->id], ['cursor' => $cursor + 1, 'updated_at' => now(), 'created_at' => now()]);
            $ordered = [...array_slice($keys, $cursor % count($keys)), ...array_slice($keys, 0, $cursor % count($keys))];

            return array_values(array_filter($ordered, function ($key) use ($provider) {
                $until = DB::table('provider_key_health')->where('fingerprint', $this->fingerprint($provider, $key))->value('cooldown_until');

                return ! $until || now()->gte($until);
            }));
        });
    }

    public function cooldown(Provider $provider, ?string $key, int $seconds): void
    {
        if ($provider->exists && $key) {
            DB::table('provider_key_health')->updateOrInsert(['fingerprint' => $this->fingerprint($provider, $key)], ['cooldown_until' => now()->addSeconds($seconds), 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    private function fingerprint(Provider $p, string $key): string
    {
        return hash_hmac('sha256',$p->id.':'.$key,config('app.key'));
    }
}

<?php

namespace App\Modules\Identity\Repositories;

use App\Modules\Identity\Models\User;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;

class IdentityRepository implements IdentityRepositoryInterface
{
    public function guest(string $hash): Workspace
    {
        return Workspace::firstOrCreate(['guest_hash' => $hash], ['expires_at' => now()->addDays(config('gehihi.guest_days'))]);
    }

    public function account(string $userId): Workspace
    {
        return Workspace::firstOrCreate(['user_id' => $userId]);
    }

    public function register(array $data): User
    {
        return User::create($data);
    }

    public function guestById(string $id, string $hash): ?Workspace
    {
        return Workspace::whereKey($id)->where('guest_hash', $hash)->where('expires_at', '>', now())->first();
    }

    public function import(string $guestId, string $targetId): void
    {
        DB::transaction(function () use ($guestId, $targetId) {
            $guest = Workspace::whereKey($guestId)->lockForUpdate()->firstOrFail();
            foreach (['projects', 'conversations', 'files', 'generation_runs'] as $table) {
                DB::table($table)->where('workspace_id', $guestId)->update(['workspace_id' => $targetId]);
            }
            $guest->update(['guest_hash' => null, 'expires_at' => now()]);
        });
    }
}

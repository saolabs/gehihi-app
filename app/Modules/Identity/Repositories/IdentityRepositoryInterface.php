<?php

namespace App\Modules\Identity\Repositories;

use App\Modules\Identity\Models\User;
use App\Modules\Workspace\Models\Workspace;

interface IdentityRepositoryInterface
{
    public function guest(string $hash): Workspace;

    public function account(string $userId): Workspace;

    public function register(array $data): User;

    public function guestById(string $id, string $hash): ?Workspace;

    public function import(string $guestId, string $targetId): void;
}

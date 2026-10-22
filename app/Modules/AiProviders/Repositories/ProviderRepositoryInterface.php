<?php

namespace App\Modules\AiProviders\Repositories;

use App\Modules\AiProviders\Models\Provider;

interface ProviderRepositoryInterface
{
    public function visible(?string $userId): array;

    public function managed(?string $userId): array;

    public function find(string $id): Provider;

    public function save(?string $id, ?string $owner, array $data): Provider;

    public function remove(string $id, ?string $owner): void;

    public function tested(string $id, bool $ok): void;

    public function keys(Provider $provider, bool $probe = false): array;

    public function cooldown(Provider $provider, ?string $key, int $seconds): void;

    public function coolingKeys(Provider $provider): int;
}

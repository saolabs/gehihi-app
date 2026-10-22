<?php

namespace App\Modules\AiRuntime\Repositories;

use App\Modules\AiRuntime\Models\GenerationRun;

interface RunRepositoryInterface
{
    public function find(string $id): GenerationRun;

    public function owned(string $ws, string $id): GenerationRun;

    public function existing(string $ws, string $requestId): ?GenerationRun;

    public function latest(string $ws, string $conversation, ?string $message = null): ?GenerationRun;

    public function reserve(string $ws): void;

    public function create(array $data): GenerationRun;

    public function claim(string $id): bool;

    public function update(string $id, array $data): void;

    public function active(string $id): bool;

    public function content(string $runId, string $text): void;

    public function finish(string $id, string $status, ?string $error = null, ?string $reason = null, ?int $epoch = null): void;

    public function resume(string $ws, string $id): GenerationRun;

    public function operation(string $id, string $key): ?array;

    public function beginOperation(string $id, string $key, string $kind, int $reservation): void;

    public function completeOperation(string $id, string $key, array $result, int $tokens, string $metering): void;

    public function operations(string $id): array;

    public function usage(string $ws): array;
}

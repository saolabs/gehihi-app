<?php

namespace App\Modules\AiRuntime\Jobs;

use App\Modules\AiRuntime\Repositories\RunRepositoryInterface;
use App\Modules\AiRuntime\Services\Executor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $runId, public int $epoch = 0) {}

    public function handle(Executor $executor): void
    {
        if ((app(RunRepositoryInterface::class)->find($this->runId)->checkpoint['epoch'] ?? 0) === $this->epoch) {
            $executor->execute($this->runId);
        }
    }

    public function failed(?\Throwable $e): void
    {
        app(RunRepositoryInterface::class)->finish($this->runId, 'failed', 'Tác vụ nền bị ngắt hoặc hết thời gian.', null, $this->epoch);
    }
}

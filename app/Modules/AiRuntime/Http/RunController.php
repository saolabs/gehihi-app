<?php

namespace App\Modules\AiRuntime\Http;

use App\Http\Controllers\ApiController;
use App\Modules\AiRuntime\Services\RunService;
use Illuminate\Http\Request;

class RunController extends ApiController
{
    public function __construct(private RunService $service) {}

    public function start(Request $r, string $id)
    {
        $data = $r->validate(['request_id' => ['required', 'uuid'], 'message' => ['required_without:regenerate_id', 'string', 'max:14000'], 'parent_id' => ['nullable', 'uuid'], 'regenerate_id' => ['nullable', 'uuid'], 'file_ids' => ['sometimes', 'array', 'max:5'], 'file_ids.*' => ['uuid'], 'mode' => ['required', 'in:chat,quick,deep'], 'thinking' => ['sometimes', 'in:auto,fast,reasoning'], 'search_provider_id' => ['nullable', 'uuid'], 'language' => ['sometimes', 'in:vi,en'], 'country' => ['sometimes', 'regex:/^[a-z]{2}$/'], 'include_domains' => ['sometimes', 'array', 'max:10'], 'include_domains.*' => ['regex:/^[a-z0-9.-]+\.[a-z]{2,}$/'], 'exclude_domains' => ['sometimes', 'array', 'max:10'], 'exclude_domains.*' => ['regex:/^[a-z0-9.-]+\.[a-z]{2,}$/'], ...$this->selectionRules()]);

        return $this->ok($this->service->start($this->ws($r), $id, $r->user()?->id, $data), 202);
    }

    public function state(Request $r, string $id)
    {
        return $this->ok($this->service->state($this->ws($r), $id));
    }

    public function latest(Request $r, string $id)
    {
        return $this->ok($this->service->latest($this->ws($r), $id));
    }

    public function cancel(Request $r, string $id)
    {
        return $this->ok($this->service->cancel($this->ws($r), $id));
    }

    public function resume(Request $r, string $id)
    {
        return $this->ok($this->service->resume($this->ws($r), $id));
    }

    public function usage(Request $r)
    {
        return $this->ok($this->service->usage($this->ws($r)));
    }

    public function events(Request $r, string $id)
    {
        $ws = $this->ws($r);
        $this->service->state($ws, $id);

        return response()->stream(function () use ($ws, $id) {
            $last = '';
            $start = microtime(true);
            do {
                $state = $this->service->state($ws, $id);
                $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $hash = hash('sha256', $json);
                if ($hash !== $last) {
                    echo "event: state\ndata: ".$json."\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }flush();
                    $last = $hash;
                }if (! in_array($state['status'], ['queued', 'running']) || connection_aborted()) {
                    break;
                }usleep(300000);
            } while (microtime(true) - $start < 15);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-store', 'X-Accel-Buffering' => 'no']);
    }
}

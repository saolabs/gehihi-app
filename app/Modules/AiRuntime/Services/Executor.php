<?php

namespace App\Modules\AiRuntime\Services;

use App\Modules\AiProviders\Services\ProviderFailure;
use App\Modules\AiProviders\Services\ProviderService;
use App\Modules\AiProviders\Services\TextGateway;
use App\Modules\AiRuntime\Repositories\RunRepositoryInterface;
use App\Modules\Research\Services\ResearchService;

class Executor
{
    public function __construct(private RunRepositoryInterface $runs, private ProviderService $providers, private TextGateway $gateway) {}

    public function guard(string $id): void
    {
        if (! $this->runs->active($id)) {
            throw new RunStopped;
        }
    }

    public function llm(string $id, string $operation, string $prompt, int $limit = 2048, ?callable $delta = null): array
    {
        $saved = $this->runs->operation($id, $operation);
        if ($saved) {
            return $saved;
        }
        $r = $this->runs->find($id);
        $s = $r->snapshot;
        $messages = $prompt === '' ? $s['messages'] : [['role' => 'user', 'content' => $prompt]];
        $messages = [['role' => 'system', 'content' => $s['system']], ...$messages];
        $textOnly = array_map(fn ($message) => ['role' => $message['role'], 'content' => $message['content']], $messages);
        $imageCount = array_sum(array_map(fn ($message) => count($message['images'] ?? []), $messages));
        $reserve = (int) ceil(strlen(json_encode($textOnly, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) / 2) + $limit + 4096 * $imageCount;
        $emitted = false;
        $last = null;
        $stage = ModelFlows::stage($operation);
        $candidates = $s['stage_candidates'][$stage ?? ''] ?? $s['candidates'];
        foreach ($candidates as $i => $candidate) {
            $key = $operation.':epoch:'.($r->checkpoint['epoch'] ?? 0).':attempt:'.$i;
            $cached = $this->runs->operation($id, $key);
            if ($cached && isset($cached['error'])) {
                continue;
            }if ($cached) {
                $this->runs->beginOperation($id, $operation, 'llm_result', 0);
                $this->runs->completeOperation($id, $operation, $cached, 0, 'reference');

                return $cached;
            }
            $this->guard($id);
            $p = $this->providers->allowed($candidate['provider_id'], $s['user_id']);
            $this->runs->beginOperation($id, $key, 'llm', $reserve);
            $this->runs->update($id, ['actual_model' => ['provider_id' => $p->id, 'provider_name' => $p->name, 'model_id' => $candidate['model_id']]]);
            try {
                $out = $this->gateway->generate($p, $candidate['model_id'], $messages, $limit, function ($piece) use (&$emitted, $delta) {
                    $emitted = true;
                    if ($delta) {
                        $delta($piece);
                    }
                }, fn () => $this->guard($id), false, $s['options']['thinking'] ?? 'auto');
                $this->runs->completeOperation($id, $key, $out, $out['tokens'], $out['metering']);
                $this->guard($id);
                $this->runs->beginOperation($id, $operation, 'llm_result', 0);
                $this->runs->completeOperation($id, $operation, $out, 0, 'reference');

                return $out;
            } catch (ProviderFailure $e) {
                $last = $e;
                if ($emitted) {
                    throw $e;
                }$this->runs->completeOperation($id, $key, ['error' => $e->getMessage()], $reserve, 'unknown');
            }
        }
        throw $last ?? new ProviderFailure('Không còn model được phép dự phòng.');
    }

    public function execute(string $id): void
    {
        if (! $this->runs->claim($id)) {
            return;
        }
        try {
            $r = $this->runs->find($id);
            if ($r->mode === 'chat') {
                $text = '';
                $lastFlush = 0;
                $this->runs->update($id, ['progress' => 'Đang trả lời', 'sources' => $r->snapshot['local_sources'] ?? []]);
                $this->llm($id, 'chat', '', 4096, function ($piece) use ($id, &$text, &$lastFlush) {
                    $text .= $piece;
                    $this->runs->content($id, $text);
                });
                $this->guard($id);
                $this->runs->content($id, $text);
                $this->runs->finish($id, 'completed');
            } else {
                app(ResearchService::class)->execute($id);
            }
        } catch (RunStopped $e) {
            $this->runs->finish($id, 'cancelled', null, 'Đã dừng theo yêu cầu hoặc tài nguyên không còn khả dụng.');
        } catch (ResearchLimit $e) {
            $this->runs->finish($id, 'partial', null, $e->getMessage());
        } catch (\Throwable $e) {
            $safe = $e instanceof ProviderFailure ? $e->getMessage() : 'Chưa hoàn tất lượt chạy. Kiểm tra cấu hình và thử lại.';
            $this->runs->finish($id,'failed',$safe);
        }
    }
}

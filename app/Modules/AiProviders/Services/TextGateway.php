<?php

namespace App\Modules\AiProviders\Services;

use App\Modules\AiProviders\Models\Provider;
use App\Modules\AiProviders\Repositories\ProviderRepositoryInterface;

class TextGateway
{
    public function __construct(private OutboundHttp $http, private ProviderService $providers, private ProviderRepositoryInterface $repo) {}

    public function generate(Provider $p, string $model, array $messages, int $limit, callable $delta, callable $guard, bool $probe = false): array
    {
        $emitted = false;
        $last = null;
        $keys = $this->repo->keys($p, $probe);
        if (! $keys) {
            throw new ProviderFailure('Các key đang cooldown. Hãy thử lại sau.');
        }
        foreach ($keys as $key) {
            $guard();
            try {
                [$url,$body] = $this->request($p, $model, $messages, $limit);
                $r = $this->http->send('POST', $url, $this->providers->headers($p, $key) + ['Content-Type' => 'application/json'], $body);
                if (! $r->successful()) {
                    throw new ProviderFailure(match ($r->status()) {
                        401,403 => 'Provider từ chối API key hoặc quyền model.',404,410 => 'Model không tồn tại hoặc đã ngừng phục vụ.',429 => 'Provider đang giới hạn tốc độ hoặc hết quota.',default => 'Provider không hoàn tất yêu cầu.'
                    }.' HTTP '.$r->status(), $r->status());
                }
                $text = '';
                $tokens = 0;
                $done = false;
                $usageSeen = false;
                $consume = function (array $data) use ($p, &$text, &$tokens, &$usageSeen, &$emitted, &$done, $delta, $guard) {
                    $guard();
                    if (isset($data['error'])) {
                        throw new ProviderFailure('Provider báo lỗi khi đang tạo nội dung.');
                    }
                    $piece = '';
                    if ($p->type === 'google') {
                        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                            if (! ($part['thought'] ?? false)) {
                                $piece .= $part['text'] ?? '';
                            }
                        }
                        if (isset($data['usageMetadata']['totalTokenCount'])) {
                            $tokens = (int) $data['usageMetadata']['totalTokenCount'];
                            $usageSeen = true;
                        }
                        if (isset($data['candidates'][0]['finishReason'])) {
                            $done = true;
                        }
                    } elseif ($p->type === 'anthropic') {
                        $piece = $data['delta']['text'] ?? '';
                        if (($data['type'] ?? '') === 'message_start') {
                            $tokens += (int) ($data['message']['usage']['input_tokens'] ?? 0);
                            $usageSeen = true;
                        }
                        if (($data['type'] ?? '') === 'message_delta') {
                            $tokens += (int) ($data['usage']['output_tokens'] ?? 0);
                            $usageSeen = true;
                        }
                        if (($data['type'] ?? '') === 'message_stop') {
                            $done = true;
                        }
                        if (isset($data['content'])) {
                            foreach ($data['content'] as $part) {
                                $piece .= $part['text'] ?? '';
                            }$tokens = (int) ($data['usage']['input_tokens'] ?? 0) + (int) ($data['usage']['output_tokens'] ?? 0);
                            $usageSeen = $tokens > 0;
                            $done = true;
                        }
                    } else {
                        $piece = $data['choices'][0]['delta']['content'] ?? $data['choices'][0]['message']['content'] ?? '';
                        if (isset($data['usage']['total_tokens'])) {
                            $tokens = (int) $data['usage']['total_tokens'];
                            $usageSeen = true;
                        }
                        if (! empty($data['choices'][0]['finish_reason'])) {
                            $done = true;
                        }
                    }
                    if (is_string($piece) && $piece !== '') {
                        $text .= $piece;
                        if (strlen($text) > 500000) {
                            throw new ProviderFailure('Câu trả lời vượt giới hạn dung lượng.');
                        }$emitted = true;
                        $delta($piece);
                    }
                };
                if (str_contains(strtolower($r->header('Content-Type') ?? ''), 'event-stream')) {
                    $stream = $r->toPsrResponse()->getBody();
                    $buffer = '';
                    $bytes = 0;
                    try {
                        while (! $stream->eof()) {
                            $guard();
                            $chunk = $stream->read(4096);
                            $bytes += strlen($chunk);
                            if ($bytes > 5000000) {
                                throw new ProviderFailure('Stream vượt giới hạn dung lượng.');
                            }$buffer .= $chunk;
                            while (($pos = strpos($buffer, "\n")) !== false) {
                                $line = rtrim(substr($buffer, 0, $pos), "\r");
                                $buffer = substr($buffer, $pos + 1);
                                if (! str_starts_with($line, 'data:')) {
                                    continue;
                                }$raw = trim(substr($line, 5));
                                if ($raw === '[DONE]') {
                                    $done = true;

                                    continue;
                                }if ($raw !== '') {
                                    $consume(json_decode($raw, true, 64, JSON_THROW_ON_ERROR));
                                }
                            }
                        }
                    } finally {
                        $stream->close();
                    }
                } else {
                    $consume($this->http->json($r));
                    $done = true;
                }
                if (! $done || $text === '') {
                    throw new ProviderFailure($text === '' ? 'Provider chưa trả nội dung văn bản.' : 'Kết nối bị ngắt trước khi hoàn tất.');
                }
                $textMessages = array_map(fn ($message) => ['role' => $message['role'], 'content' => $message['content']], $messages);
                $imageTokens = 4096 * array_sum(array_map(fn ($message) => count($message['images'] ?? []), $messages));

                return ['text' => $text, 'tokens' => $usageSeen ? $tokens : (int) ceil((strlen(json_encode($textMessages, JSON_UNESCAPED_UNICODE)) + strlen($text)) / 2) + $imageTokens, 'metering' => $usageSeen ? 'reported' : 'estimated'];
            } catch (ProviderFailure $e) {
                $last = $e;
                if ($emitted) {
                    throw $e;
                }if (in_array($e->status, [401, 403, 429], true)) {
                    if (! $probe) {
                        $this->repo->cooldown($p, $key, $e->status === 429 ? 60 : 600);
                    }

continue;
                }throw $e;
            }
        }
        throw $last ?? new ProviderFailure('Không có key khả dụng.');
    }

    private function request(Provider $p, string $model, array $messages, int $limit): array
    {
        $system = implode("\n\n", array_column(array_filter($messages, fn ($m) => $m['role'] === 'system'), 'content'));
        $turns = array_values(array_filter($messages, fn ($m) => $m['role'] !== 'system'));
        if ($p->type === 'google') {
            $contents = array_map(function ($m) {
                $parts = [['text' => $m['content']]];
                foreach ($m['images'] ?? [] as $i) {
                    $parts[] = ['inlineData' => ['mimeType' => $i['mime'], 'data' => $i['data']]];
                }

return ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => $parts];
            }, $turns);

            return [$p->base_url.'/models/'.rawurlencode($model).':streamGenerateContent?alt=sse', ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => $contents, 'generationConfig' => ['maxOutputTokens' => $limit]]];
        }
        if ($p->type === 'anthropic') {
            $turns = array_map(function ($m) {
                $parts = [['type' => 'text', 'text' => $m['content']]];
                foreach ($m['images'] ?? [] as $i) {
                    $parts[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $i['mime'], 'data' => $i['data']]];
                }

return ['role' => $m['role'], 'content' => $parts];
            }, $turns);

            return [$p->base_url.'/messages', ['model' => $model, 'max_tokens' => $limit, 'system' => $system, 'messages' => $turns, 'stream' => true]];
        }
        $turns = array_map(function ($m) {
            $parts = [['type' => 'text', 'text' => $m['content']]];
            foreach ($m['images'] ?? [] as $i) {
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$i['mime'].';base64,'.$i['data']]];
            }

return ['role' => $m['role'], 'content' => count($parts) > 1 ? $parts : $m['content']];
        }, $turns);

        return [$p->base_url.'/chat/completions', ['model' => $model, 'messages' => [['role' => 'system', 'content' => $system], ...$turns], $p->type === 'openai' ? 'max_completion_tokens' : 'max_tokens' => $limit, 'stream' => true, 'stream_options' => ['include_usage' => true]]];
    }
}

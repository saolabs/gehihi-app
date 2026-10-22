<?php

namespace App\Modules\AiProviders\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OutboundHttp
{
    public function options(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $scheme = $parts['scheme'] ?? '';
        if (! $host || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \RuntimeException('URL không hợp lệ.');
        }
        $trusted = in_array($host, config('gehihi.egress_allowlist'), true);
        if ($scheme !== 'https' && ! ($trusted && $scheme === 'http')) {
            throw new \RuntimeException('Endpoint cần HTTPS; local chỉ mở qua allowlist máy chủ.');
        }
        if ($trusted) {
            return ['allow_redirects' => false, 'stream' => true];
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(gethostbynamel($host) ?: [], array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        if (! $addresses) {
            throw new \RuntimeException('Không phân giải được địa chỉ endpoint.');
        }
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Không được truy cập mạng riêng/địa chỉ nội bộ.');
            }
        }
        $ip = $addresses[0];
        $port = $parts['port'] ?? 443;
        $pin = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        return ['allow_redirects' => false, 'stream' => true, 'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$pin]]];
    }

    public function send(string $method, string $url, array $headers = [], array $body = [], int $timeout = 60)
    {
        $request = Http::withOptions($this->options($url))->withHeaders($headers)->connectTimeout(10)->timeout($timeout);
        try {
            return $method === 'GET' ? $request->get($url) : $request->post($url, $body);
        } catch (ConnectionException $e) {
            throw new ProviderFailure('Không kết nối được provider. Kiểm tra endpoint hoặc thử lại.', 0);
        }
    }

    public function json($response, int $max = 2097152): array
    {
        $stream = $response->toPsrResponse()->getBody();
        $text = '';
        while (! $stream->eof()) {
            $text .= $stream->read(8192);
            if (strlen($text) > $max) {
                throw new \RuntimeException('Phản hồi vượt giới hạn dung lượng.');
            }
        }

        return json_decode($text,true,64,JSON_THROW_ON_ERROR);
    }
}

<?php

namespace App\Modules\AiProviders\Services;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\BufferStream;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\PumpStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Streams response bodies through cURL so CURLOPT_RESOLVE remains effective. */
class CurlStreamHandler
{
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $multi = new CurlMultiHandler(['select_timeout' => 0.05]);
        $buffer = new BufferStream(PHP_INT_MAX);
        $response = null;
        $done = false;
        $failure = null;
        $position = 0;
        $bytes = 0;
        $onHeaders = $options['on_headers'] ?? null;
        $options['on_headers'] = function (ResponseInterface $headers) use (&$response, $onHeaders) {
            if ($onHeaders) {
                $onHeaders($headers);
            }
            $response = $headers;
        };
        // Bound the decoded body even when a server omits Content-Length.
        $options['sink'] = FnStream::decorate($buffer, ['write' => function (string $chunk) use ($buffer, &$bytes): int {
            $bytes += strlen($chunk);
            if ($bytes > 5000000) {
                throw new ProviderFailure('Phản hồi vượt giới hạn dung lượng.');
            }

            return $buffer->write($chunk);
        }]);
        $transfer = $multi($request, $options);
        $transfer->then(
            function () use (&$done) {
                $done = true;
            },
            function ($error) use (&$done, &$failure) {
                $done = true;
                $failure = $error;
            }
        );

        try {
            // Return at headers, rather than waiting for the entire SSE response.
            while ($response === null && ! $done) {
                $multi->tick();
            }
            if ($failure && $response === null) {
                return Create::rejectionFor($failure);
            }
            if ($response === null) {
                throw new ProviderFailure('Provider chưa trả phản hồi HTTP.');
            }

            $read = function (int $length) use ($multi, $buffer, &$done, &$failure, &$position): string|false {
                if ($length < 0) {
                    throw new \InvalidArgumentException('Độ dài đọc không hợp lệ.');
                }
                if ($length === 0) {
                    return '';
                }
                while ($buffer->getSize() === 0 && ! $done) {
                    $multi->tick();
                }
                if ($buffer->getSize() > 0) {
                    $chunk = $buffer->read($length);
                    $position += strlen($chunk);

                    return $chunk;
                }
                if ($failure) {
                    throw new ProviderFailure('Kết nối bị ngắt khi đang đọc phản hồi provider.');
                }

                return false;
            };
            $pump = new PumpStream($read);
            $body = FnStream::decorate($pump, [
                // PumpStream::read fills the requested length. SSE must return
                // the first available chunk so short answers also stream live.
                'read' => function (int $length) use ($read): string {
                    $chunk = $read($length);

                    return $chunk === false ? '' : $chunk;
                },
                'tell' => function () use (&$position): int {
                    return $position;
                },
                'eof' => function () use (&$done, &$failure, $buffer): bool {
                    return $done && ! $failure && $buffer->getSize() === 0;
                },
                'close' => function () use ($transfer, $pump, $buffer) {
                    $transfer->cancel();
                    $pump->close();
                    $buffer->close();
                },
            ]);

            return Create::promiseFor($response->withBody($body));
        } catch (\Throwable $error) {
            $transfer->cancel();
            $buffer->close();

            return Create::rejectionFor($error);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Modules\AiProviders\Services\OutboundHttp;
use App\Modules\AiProviders\Services\ProviderFailure;
use Tests\TestCase;

class CurlTransportTest extends TestCase
{
    private $server;

    private array $pipes = [];

    private string $directory;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/gehihi-curl-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $cert = proc_open(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=provider.example.test', '-addext', 'subjectAltName=DNS:provider.example.test', '-keyout', $this->directory.'/key.pem', '-out', $this->directory.'/cert.pem'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        fclose($pipes[0]);
        $this->assertSame(0, proc_close($cert));
        $this->server = proc_open([PHP_BINARY, base_path('tests/Fixtures/curl-stream-server.php'), $this->directory.'/cert.pem', $this->directory.'/key.pem'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $this->pipes);
        stream_set_timeout($this->pipes[1], 5);
        $address = trim(fgets($this->pipes[1]));
        $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $address);
        $this->port = (int) substr(strrchr($address, ':'), 1);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            } proc_close($this->server);
        }
        foreach (glob($this->directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function request(string $path, string $host = 'provider.example.test')
    {
        $port = $this->port;
        $settings = ['allow_redirects' => false, 'stream' => true, 'proxy' => '', 'verify' => $this->directory.'/cert.pem', 'curl' => [CURLOPT_RESOLVE => ["$host:$port:127.0.0.1"]]];
        // Pin the fixture IP explicitly; normal OutboundHttp still rejects private IPs.
        $http = new class($settings) extends OutboundHttp
        {
            public function __construct(private array $settings) {}

            public function options(string $url): array
            {
                return $this->settings;
            }
        };

        return $http->send('GET', "https://$host:$port$path", [], [], 5);
    }

    public function test_pinned_curl_returns_headers_and_short_chunks_before_response_finishes(): void
    {
        $start = microtime(true);
        $response = $this->request('/stream');
        $this->assertSame(200, $response->status());
        $body = $response->toPsrResponse()->getBody();
        $this->assertSame("data: first\n\n", $body->read(4096));
        $this->assertLessThan(0.8, microtime(true) - $start, 'SSE was buffered until completion.');
        $this->assertSame("data: second\n\n", $body->read(4096));
        $this->assertSame('', $body->read(4096));
        $this->assertTrue($body->eof());
        $this->assertSame(strlen("data: first\n\ndata: second\n\n"), $body->tell());
        $body->close();
    }

    public function test_tls_hostname_verification_and_redirect_blocking_remain_enabled(): void
    {
        $response = $this->request('/redirect');
        $this->assertSame(302, $response->status());
        $this->assertSame('http://127.0.0.1/private', $response->header('Location'));
        $response->toPsrResponse()->getBody()->close();
        $this->expectException(ProviderFailure::class);
        $this->request('/zero', 'wrong.example.test');
    }

    public function test_closing_the_body_cancels_without_waiting_for_the_remaining_response(): void
    {
        $body = $this->request('/stream')->toPsrResponse()->getBody();
        $this->assertSame("data: first\n\n", $body->read(4096));
        $start = microtime(true);
        $body->close();
        $this->assertLessThan(0.5, microtime(true) - $start);
        $this->assertFalse($body->isReadable());
    }

    public function test_zero_body_is_not_lost_and_truncated_stream_is_not_successful(): void
    {
        $this->assertSame('0', $this->request('/zero')->body());
        $body = $this->request('/truncated')->toPsrResponse()->getBody();
        $this->assertSame('first', $body->read(4096));
        $this->expectException(ProviderFailure::class);
        try {
            $body->read(4096);
        } finally {
            $body->close();
        }
    }

    public function test_oversized_response_without_content_length_is_stopped(): void
    {
        $body = $this->request('/oversized')->toPsrResponse()->getBody();
        $this->expectException(ProviderFailure::class);
        try {
            while (! $body->eof()) {
                $body->read(8192);
            }
        } finally {
            $body->close();
        }
    }
}

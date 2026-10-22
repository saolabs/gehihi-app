<?php

namespace App\Modules\Research\Services;

use App\Modules\AiProviders\Services\OutboundHttp;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

class PageReader
{
    public function __construct(private OutboundHttp $http) {}

    public function read(string $url, callable $guard): array
    {
        for ($i = 0; $i < 4; $i++) {
            $guard();
            $r = $this->http->send('GET', $url, ['Accept' => 'text/html,text/plain', 'User-Agent' => 'GehihiResearch/1.0'], [], 20);
            if ($r->redirect()) {
                $location = $r->header('Location');
                if (! $location) {
                    throw new \RuntimeException('Chuyển hướng không hợp lệ.');
                }$url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }
            if (! $r->successful()) {
                throw new \RuntimeException('Không đọc được trang.');
            }
            $mime = strtolower($r->header('Content-Type') ?? '');
            if (! str_contains($mime, 'html') && ! str_contains($mime, 'text/plain')) {
                throw new \RuntimeException('Trang không phải HTML/văn bản được hỗ trợ.');
            }
            $stream = $r->toPsrResponse()->getBody();
            $html = '';
            while (! $stream->eof()) {
                $guard();
                $html .= $stream->read(8192);
                if (strlen($html) > 2097152) {
                    $stream->close();
                    throw new \RuntimeException('Trang vượt giới hạn đọc.');
                }
            }$stream->close();
            if (str_contains($mime, 'html')) {
                $doc = new \DOMDocument;
                $old = libxml_use_internal_errors(true);
                $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                libxml_clear_errors();
                libxml_use_internal_errors($old);
                $xpath = new \DOMXPath($doc);
                foreach ($xpath->query('//script|//style|//noscript|//nav|//footer|//header|//form|//svg') as $node) {
                    $node->parentNode?->removeChild($node);
                }
                $content = $xpath->query('//article|//main')->item(0)?->textContent ?? $doc->textContent;
                $title = trim($doc->getElementsByTagName('title')->item(0)?->textContent ?? $url);
                $date = $xpath->query('//meta[@property="article:published_time"]/@content')->item(0)?->nodeValue;
            } else {
                $content = $html;
                $title = $url;
                $date = null;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $content) ?? '');
            if (mb_strlen($text) < 80) {
                throw new \RuntimeException('Trang không đủ nội dung đọc được.');
            }

            return ['url' => $url, 'title' => mb_substr($title, 0, 250), 'text' => mb_substr($text, 0, 12000), 'kind' => 'page_content', 'observed_at' => now()->toIso8601String(), 'published_at' => $date, 'partial' => mb_strlen($text) > 12000];
        }
        throw new \RuntimeException('Quá nhiều chuyển hướng.');
    }
}

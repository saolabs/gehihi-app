<?php

namespace App\Modules\AiProviders\Services;

class ProviderCatalog
{
    // Same curated text catalog as seo-expert/services/google-provider/catalog.json (2026-10-17.1).
    // Loading this catalog does not validate a credential; generation/probe does that separately.
    public const VERTEX_MODELS = ['gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite'];

    public const TYPES = [
        'google' => ['label' => 'Google Gemini', 'kind' => 'llm', 'base' => 'https://generativelanguage.googleapis.com/v1beta'],
        'google_vertex' => ['label' => 'Google Cloud (Vertex AI)', 'kind' => 'llm', 'base' => 'https://aiplatform.googleapis.com/v1/publishers/google'],
        'openai' => ['label' => 'OpenAI', 'kind' => 'llm', 'base' => 'https://api.openai.com/v1'],
        'anthropic' => ['label' => 'Anthropic', 'kind' => 'llm', 'base' => 'https://api.anthropic.com/v1'],
        'openai_compatible' => ['label' => 'OpenAI-compatible', 'kind' => 'llm', 'base' => '', 'base_url_editable' => true],
        'deepseek' => ['label' => 'DeepSeek', 'kind' => 'llm', 'base' => 'https://api.deepseek.com/v1'],
        'cerebras' => ['label' => 'Cerebras', 'kind' => 'llm', 'base' => 'https://api.cerebras.ai/v1'],
        'nvidia' => ['label' => 'NVIDIA Chat', 'kind' => 'llm', 'base' => 'https://integrate.api.nvidia.com/v1'],
        'searxng' => ['label' => 'SearXNG', 'kind' => 'search', 'base' => '', 'base_url_editable' => true],
        'tavily' => ['label' => 'Tavily', 'kind' => 'search', 'base' => 'https://api.tavily.com'],
        'serper' => ['label' => 'Serper', 'kind' => 'search', 'base' => 'https://google.serper.dev'],
        'serpapi' => ['label' => 'SerpAPI', 'kind' => 'search', 'base' => 'https://serpapi.com'],
        'brave' => ['label' => 'Brave Search', 'kind' => 'search', 'base' => 'https://api.search.brave.com/res/v1'],
        'exa' => ['label' => 'Exa', 'kind' => 'search', 'base' => 'https://api.exa.ai'],
    ];

    public static function types(?string $kind = null): array
    {
        return $kind ? array_filter(self::TYPES, fn ($type) => $type['kind'] === $kind) : self::TYPES;
    }

    public static function editableBaseUrl(string $type): bool
    {
        return self::TYPES[$type]['base_url_editable'] ?? false;
    }

    /** Fixed providers always use the endpoint shipped in code, including legacy records. */
    public static function baseUrl(string $type, ?string $custom = null): string
    {
        return rtrim(self::editableBaseUrl($type) ? trim($custom ?? '') : self::TYPES[$type]['base'], '/');
    }
}

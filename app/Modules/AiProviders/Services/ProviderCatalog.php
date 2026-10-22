<?php

namespace App\Modules\AiProviders\Services;

class ProviderCatalog
{
    public const TYPES = [
        'google' => ['label' => 'Google Gemini', 'kind' => 'llm', 'base' => 'https://generativelanguage.googleapis.com/v1beta'],
        'openai' => ['label' => 'OpenAI', 'kind' => 'llm', 'base' => 'https://api.openai.com/v1'],
        'anthropic' => ['label' => 'Anthropic', 'kind' => 'llm', 'base' => 'https://api.anthropic.com/v1'],
        'openai_compatible' => ['label' => 'OpenAI-compatible', 'kind' => 'llm', 'base' => ''],
        'deepseek' => ['label' => 'DeepSeek', 'kind' => 'llm', 'base' => 'https://api.deepseek.com/v1'],
        'cerebras' => ['label' => 'Cerebras', 'kind' => 'llm', 'base' => 'https://api.cerebras.ai/v1'],
        'nvidia' => ['label' => 'NVIDIA Chat', 'kind' => 'llm', 'base' => 'https://integrate.api.nvidia.com/v1'],
        'searxng' => ['label' => 'SearXNG', 'kind' => 'search', 'base' => ''],
        'tavily' => ['label' => 'Tavily', 'kind' => 'search', 'base' => 'https://api.tavily.com'],
        'serper' => ['label' => 'Serper', 'kind' => 'search', 'base' => 'https://google.serper.dev'],
        'serpapi' => ['label' => 'SerpAPI', 'kind' => 'search', 'base' => 'https://serpapi.com'],
        'brave' => ['label' => 'Brave Search', 'kind' => 'search', 'base' => 'https://api.search.brave.com/res/v1'],
        'exa' => ['label' => 'Exa', 'kind' => 'search', 'base' => 'https://api.exa.ai'],
    ];
}

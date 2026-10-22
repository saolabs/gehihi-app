<?php

return [
    'guest_days' => (int) env('GEHIHI_GUEST_DAYS', 7), 'daily_runs' => (int) env('GEHIHI_DAILY_RUNS', 50),
    'daily_tokens' => (int) env('GEHIHI_DAILY_TOKENS', 200000), 'chat_tokens' => (int) env('GEHIHI_CHAT_TOKENS', 16000),
    'research_tokens' => (int) env('GEHIHI_RESEARCH_TOKENS', 48000), 'concurrency' => (int) env('GEHIHI_CONCURRENCY', 2),
    'egress_allowlist' => array_values(array_filter(explode(',', env('GEHIHI_EGRESS_ALLOWLIST', '')))),
    'pdftotext' => env('GEHIHI_PDFTOTEXT', 'pdftotext'), 'upload_bytes' => 10 * 1024 * 1024,
    'research' => ['quick' => ['queries' => 2, 'pages' => 4, 'rounds' => 1, 'seconds' => 90], 'deep' => ['queries' => 8, 'pages' => 12, 'rounds' => 3, 'seconds' => 240]],
];

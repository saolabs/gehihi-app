<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Compiler Configuration
    |--------------------------------------------------------------------------
    |
    | Multi-context compiler configuration for Saola framework in Crazify.
    | Each context has its own view directories, output paths, and settings.
    |
    */
    'compiler' => [
        // Web context (landing, studio workspace and shared artifacts)
        'web' => [
            'views' => [
                resource_path('views/web'),
                resource_path('views/_system'),
            ],
            'output' => resource_path('js/saola/web/views'),
            'target' => 'typescript',
            'cache' => storage_path('framework/views/compiled/web'),
            'minify' => env('APP_ENV') === 'production',
            'sourcemap' => env('APP_DEBUG', false),
            'preserve_comments' => false,
            'strict_mode' => true,
        ],

        // Admin context
        'admin' => [
            'views' => [
                resource_path('views/admin'),
                resource_path('views/_system'),
            ],
            'output' => resource_path('js/saola/admin/views'),
            'target' => 'typescript',
            'cache' => storage_path('framework/views/compiled/admin'),
            'minify' => env('APP_ENV') === 'production',
            'sourcemap' => env('APP_DEBUG', false),
            'preserve_comments' => false,
            'strict_mode' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Compiler Options
    |--------------------------------------------------------------------------
    */
    'options' => [
        'parallel' => true,
        'cache_enabled' => true,
        'cache_ttl' => 3600,

        'indent' => '    ',
        'line_ending' => PHP_EOL,
        'quote_style' => 'single',

        'tree_shaking' => true,
        'dead_code_elimination' => true,
        'constant_folding' => true,

        'reactive_system' => 'proxy',
        'virtual_dom' => true,
        'ssr_support' => true,
        'hydration' => true,

        'custom_directives' => [],
    ],

    'extensions' => [
        'blade' => '.blade.php',
        'typescript' => '.ts',
        'javascript' => '.js',
    ],

    'errors' => [
        'throw_on_error' => env('APP_DEBUG', false),
        'log_errors' => true,
        'log_channel' => 'daily',
    ],

    'hydration' => [
        'enabled' => true,
        'mode' => 'automatic',
        'selector' => '[data-hydrate]',
        'attrKeys' => [
            'hydrate' => 'data-hydrate',
            'id' => 'data-sao-id',
            'props' => 'data-sao-props',
            'events' => 'data-sao-events',
            'ssr' => 'data-sao-ssr',
        ],
    ],
];

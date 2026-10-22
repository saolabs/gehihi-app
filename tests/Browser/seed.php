<?php

use App\Modules\AiProviders\Models\Provider;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_starts_with(config('database.connections.sqlite.database'), '/private/tmp/gehihi-browser')) {
    throw new RuntimeException('Browser fixtures require a separate temporary test database.');
}
foreach ([['name' => 'Local QA AI', 'kind' => 'llm', 'type' => 'openai_compatible', 'base_url' => 'http://127.0.0.1:8011/v1', 'models' => ['local-qa-model'], 'default_model' => 'local-qa-model'], ['name' => 'Local QA Search', 'kind' => 'search', 'type' => 'searxng', 'base_url' => 'http://127.0.0.1:8011', 'models' => [], 'default_model' => '']] as $provider) {
    Provider::updateOrCreate(['name' => $provider['name']], [...$provider, 'enabled' => true, 'is_default' => true]);
}

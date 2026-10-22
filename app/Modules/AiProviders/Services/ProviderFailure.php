<?php

namespace App\Modules\AiProviders\Services;

class ProviderFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}

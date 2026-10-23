<?php

namespace App\Modules\AiProviders\Services;

class ProviderFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }

    /** Google báo key sai bằng 400 API_KEY_INVALID thay vì 401: coi như 401 để báo đúng và xoay sang key kế. */
    public static function status($response): int
    {
        return $response->status() === 400 && str_contains($response->body(), 'API_KEY_INVALID') ? 401 : $response->status();
    }
}

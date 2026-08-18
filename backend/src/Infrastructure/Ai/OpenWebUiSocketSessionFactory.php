<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

interface OpenWebUiSocketSessionFactory
{
    public function create(string $baseUrl, string $token, float $connectTimeoutSeconds): OpenWebUiSocketSession;
}

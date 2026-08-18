<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Ai;

use App\Infrastructure\Ai\OpenWebUiSocketSession;
use App\Infrastructure\Ai\OpenWebUiSocketSessionFactory;

final class FakeOpenWebUiSocketSessionFactory implements OpenWebUiSocketSessionFactory
{
    /** @var array{string, string, float}|null */
    public ?array $arguments = null;

    public function __construct(private readonly OpenWebUiSocketSession $session)
    {
    }

    public function create(string $baseUrl, string $token, float $connectTimeoutSeconds): OpenWebUiSocketSession
    {
        $this->arguments = [$baseUrl, $token, $connectTimeoutSeconds];
        return $this->session;
    }
}

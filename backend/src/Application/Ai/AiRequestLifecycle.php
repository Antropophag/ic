<?php

declare(strict_types=1);

namespace App\Application\Ai;

interface AiRequestLifecycle
{
    /**
     * @param callable(): mixed $operation
     * @param callable(): int $statusCode
     * @param callable(): (string|null) $location
     * @return array{body: mixed, statusCode: int, location: string|null, replayed: bool}
     */
    public function execute(
        int $actorId,
        string $method,
        string $route,
        string $key,
        string $requestHash,
        callable $operation,
        callable $statusCode,
        callable $location,
    ): array;
}

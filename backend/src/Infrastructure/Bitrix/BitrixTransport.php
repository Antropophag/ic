<?php

declare(strict_types=1);

namespace App\Infrastructure\Bitrix;

interface BitrixTransport
{
    /** @param array<string, mixed> $parameters
     *  @return array<string, mixed>
     */
    public function call(string $method, array $parameters = []): array;
}

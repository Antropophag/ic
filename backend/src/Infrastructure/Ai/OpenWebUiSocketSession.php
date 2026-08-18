<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

interface OpenWebUiSocketSession
{
    public function connect(): string;

    /** @return array<string, mixed>|null */
    public function waitForEvents(float $timeoutSeconds): ?array;

    public function disconnect(): void;
}

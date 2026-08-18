<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Ai;

use App\Infrastructure\Ai\OpenWebUiSocketSession;

final class FakeOpenWebUiSocketSession implements OpenWebUiSocketSession
{
    public int $disconnects = 0;

    /** @param list<array<string, mixed>|null> $events */
    public function __construct(
        private readonly string $sid,
        private array $events,
        private readonly ?\Throwable $waitError = null,
        private readonly ?\Throwable $connectError = null,
        private readonly bool $honorTimeout = false,
    ) {
    }

    public function connect(): string
    {
        if ($this->connectError !== null) {
            throw $this->connectError;
        }
        return $this->sid;
    }

    public function waitForEvents(float $timeoutSeconds): ?array
    {
        if ($this->waitError !== null) {
            throw $this->waitError;
        }
        if ($this->honorTimeout && $this->events === []) {
            usleep((int) ceil($timeoutSeconds * 1_000_000));
        }
        return array_shift($this->events);
    }

    public function disconnect(): void
    {
        $this->disconnects++;
    }
}

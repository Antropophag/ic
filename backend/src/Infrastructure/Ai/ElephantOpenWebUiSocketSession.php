<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use ElephantIO\Client;
use RuntimeException;

final class ElephantOpenWebUiSocketSession implements OpenWebUiSocketSession
{
    /** @var list<array<string, mixed>> */
    private array $pendingEvents = [];

    public function __construct(
        private Client $client,
        private ElephantOpenWebUiSocketEngine $engine,
    ) {
    }

    public function connect(): string
    {
        $this->client->connect();
        $sid = $this->engine->namespaceSid();
        if ($sid === null || $sid === '') {
            throw new RuntimeException('Socket.IO namespace SID is missing.');
        }

        return $sid;
    }

    public function waitForEvents(float $timeoutSeconds): ?array
    {
        if ($this->pendingEvents !== []) {
            return array_shift($this->pendingEvents);
        }
        // Bound one socket read, not the completion as a whole, so ping and
        // completion packets are drained while the caller waits indefinitely.
        $packet = $this->client->drain(min($timeoutSeconds, 2.0));
        if ($packet !== null) {
            foreach ($packet->flatten() as $item) {
                if ($item->event === 'events' && is_array($item->data)) {
                    $this->pendingEvents[] = $item->data;
                }
            }
        }

        return array_shift($this->pendingEvents);
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }
}

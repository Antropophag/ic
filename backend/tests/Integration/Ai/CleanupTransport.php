<?php

declare(strict_types=1);

namespace Tests\Integration\Ai;

use App\Infrastructure\Ai\OpenWebUiTransport;

final class CleanupTransport implements OpenWebUiTransport
{
    public function beginOperation(): void
    {
    }

    public bool $failDelete = false;

    public function socketSessionId(): string
    {
        throw new \LogicException();
    }

    public function uploadFile(string $name, string $mimeType, string $path): array
    {
        throw new \LogicException();
    }

    public function deleteFile(string $fileId): void
    {
        if ($this->failDelete) {
            throw new \RuntimeException('delete failed');
        }
    }

    public function createChat(array $chat): string
    {
        throw new \LogicException();
    }

    public function submit(array $payload): void
    {
        throw new \LogicException();
    }

    public function completion(string $chatId, string $messageId): string
    {
        throw new \LogicException();
    }

    public function disconnect(): void
    {
    }
}

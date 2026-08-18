<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

interface OpenWebUiTransport
{
    public function beginOperation(): void;

    public function socketSessionId(): string;

    /** @return array<string, mixed> */
    public function uploadFile(string $name, string $mimeType, string $path): array;

    public function deleteFile(string $fileId): void;

    /** @param array<string, mixed> $chat */
    public function createChat(array $chat): string;

    /** @param array<string, mixed> $payload */
    public function submit(array $payload): void;

    public function completion(string $chatId, string $messageId): string;

    public function disconnect(): void;
}

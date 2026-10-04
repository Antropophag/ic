<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Ai;

use App\Infrastructure\Ai\OpenWebUiTransport;

final class RecordingOpenWebUiTransport implements OpenWebUiTransport
{
    /** @var array<string, mixed> */
    public array $payload = [];
    /** @var array<string, mixed> */
    public array $chat = [];
    public int $disconnects = 0;
    /** @var list<array{name: string, mimeType: string, path: string}> */
    public array $uploads = [];
    /** @var list<string> */
    public array $deletedFileIds = [];

    public function __construct(
        private readonly ?\Throwable $completionError = null,
        private readonly ?\Throwable $deleteError = null,
    ) {
    }

    public function beginOperation(): void
    {
    }

    public function socketSessionId(): string
    {
        return 'socket-sid';
    }

    public function uploadFile(string $name, string $mimeType, string $path): array
    {
        $this->uploads[] = compact('name', 'mimeType', 'path');
        return [
            'id' => 'uploaded-file-' . count($this->uploads),
            'filename' => $name,
            'meta' => ['content_type' => $mimeType, 'size' => 14],
            'data' => ['status' => 'completed'],
        ];
    }

    public function deleteFile(string $fileId): void
    {
        $this->deletedFileIds[] = $fileId;
        if ($this->deleteError !== null) {
            throw $this->deleteError;
        }
    }

    public function submit(array $payload): void
    {
        $this->payload = $payload;
    }

    public function createChat(array $chat): string
    {
        $this->chat = $chat;
        return 'server-chat-' . spl_object_id($this);
    }

    public function completion(string $chatId, string $messageId): string
    {
        if ($this->completionError !== null) {
            throw $this->completionError;
        }
        return 'Ответ';
    }

    public function disconnect(): void
    {
        $this->disconnects++;
    }
}

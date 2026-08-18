<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Ai\LizaPort;
use App\Application\Ai\LizaReply;
use App\Application\Ai\TechnicalSpecificationFile;

final readonly class OpenWebUiLizaAdapter implements LizaPort
{
    public function __construct(
        private OpenWebUiTransport $transport,
        private string $model,
        private ?AiFileCleanupQueue $cleanupQueue = null,
    ) {
    }

    public function start(string $prompt, ?TechnicalSpecificationFile $file = null): LizaReply
    {
        return $this->send($prompt, $file);
    }

    private function send(string $prompt, ?TechnicalSpecificationFile $file): LizaReply
    {
        $userId = $this->uuid();
        $assistantId = $this->uuid();
        $uploadedFile = null;
        try {
            $this->transport->beginOperation();
            $sessionId = $this->transport->socketSessionId();
            if ($file !== null) {
                $uploadedFile = $this->transport->uploadFile($file->name, $file->mimeType, $file->path);
            }
            $fileId = $uploadedFile === null ? null : (string) $uploadedFile['id'];
            $files = $uploadedFile === null
                ? []
                : [$this->fileDescriptor($uploadedFile, $file, $fileId)];
            $chatId = $this->transport->createChat($this->initialChat($userId, $assistantId, $prompt, $files));
            $userMessage = $this->userMessage($userId, $assistantId, null, $prompt, $files);
            $messages = [['id' => $userId, 'role' => 'user', 'content' => $prompt]];
            $payload = [
                'model' => $this->model,
                'messages' => $messages,
                'stream' => true,
                // Open WebUI expects a JSON object here. An empty PHP array is
                // encoded as [] and rejected because the backend calls .get().
                'params' => (object) [],
                'features' => [
                    'voice' => false,
                    'image_generation' => false,
                    'code_interpreter' => false,
                    'web_search' => false,
                ],
                'chat_id' => $chatId,
                'id' => $assistantId,
                'session_id' => $sessionId,
                'parent_id' => null,
                'user_message' => $userMessage,
                'message_ids' => [$this->model => $assistantId],
                'background_tasks' => ['follow_up_generation' => false],
            ];
            if ($files !== []) {
                $payload['files'] = $files;
            }
            $this->transport->submit($payload);
            return new LizaReply($chatId, $assistantId, $this->transport->completion($chatId, $assistantId));
        } finally {
            if ($uploadedFile !== null) {
                try {
                    $this->transport->deleteFile((string) $uploadedFile['id']);
                } catch (\Throwable $cleanupError) {
                    \Yii::warning([
                        'event' => 'liza_uploaded_file_cleanup_failed',
                        'external_file_id' => (string) $uploadedFile['id'],
                        'error_class' => $cleanupError::class,
                    ], __METHOD__);
                    try {
                        $this->cleanupQueue?->schedule((string) $uploadedFile['id'], $cleanupError);
                    } catch (\Throwable $queueError) {
                        \Yii::error([
                            'event' => 'liza_file_cleanup_queue_failed',
                            'external_file_id' => (string) $uploadedFile['id'],
                            'error_class' => $queueError::class,
                        ], __METHOD__);
                    }
                }
            }
            $this->transport->disconnect();
        }
    }

    /**
     * @param array<string, mixed> $uploadedFile
     * @return array<string, mixed>
     */
    private function fileDescriptor(
        array $uploadedFile,
        TechnicalSpecificationFile $file,
        string $fileId,
    ): array {
        $descriptor = [
            'type' => 'file',
            'file' => $uploadedFile,
            'id' => $fileId,
            'url' => $fileId,
            'name' => $file->name,
            'status' => 'uploaded',
            'size' => $file->size,
            'error' => '',
            'itemId' => $this->uuid(),
            'content_type' => $file->mimeType,
        ];
        $collectionName = $uploadedFile['meta']['collection_name'] ?? $uploadedFile['collection_name'] ?? null;
        if (is_string($collectionName) && $collectionName !== '') {
            $descriptor['collection_name'] = $collectionName;
        }
        return $descriptor;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private function userMessage(
        string $userId,
        string $assistantId,
        ?string $parentId,
        string $prompt,
        array $files,
    ): array {
        $message = [
            'id' => $userId,
            'parentId' => $parentId,
            'childrenIds' => [$assistantId],
            'role' => 'user',
            'content' => $prompt,
            'models' => [$this->model],
            'timestamp' => time(),
        ];
        if ($files !== []) {
            $message['files'] = $files;
        }
        return $message;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private function initialChat(string $userId, string $assistantId, string $prompt, array $files): array
    {
        $user = [
            'id' => $userId,
            'role' => 'user',
            'content' => $prompt,
            'parentId' => null,
            'childrenIds' => [$assistantId],
            'models' => [$this->model],
        ];
        if ($files !== []) {
            $user['files'] = $files;
        }
        $assistant = [
            'id' => $assistantId,
            'role' => 'assistant',
            'content' => '',
            'parentId' => $userId,
            'childrenIds' => [],
            'model' => $this->model,
            'modelName' => $this->model,
            'modelIdx' => 0,
        ];

        return [
            'title' => 'Анализ технического задания',
            'models' => [$this->model],
            'messages' => [$user, $assistant],
            'history' => [
                'currentId' => $assistantId,
                'messages' => [$userId => $user, $assistantId => $assistant],
            ],
        ];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Ai\AiConversationPort;
use App\Application\Ai\LizaReply;
use App\Infrastructure\Clock;
use yii\db\Connection;

final readonly class DatabaseAiConversationStore implements AiConversationPort
{
    public function __construct(private Connection $db)
    {
    }

    public function create(string $taskType, int $requestId, int $documentVersionId, int $actorId, LizaReply $reply): string
    {
        $id = bin2hex(random_bytes(16));
        $this->db->createCommand()->insert('{{%ai_conversations}}', [
            'id' => $id,
            'task_type' => $taskType,
            'request_id' => $requestId,
            'document_version_id' => $documentVersionId,
            'actor_id' => $actorId,
            'liza_chat_id' => $reply->chatId,
            'parent_message_id' => $reply->messageId,
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ])->execute();
        return $id;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ai;

use App\Application\Ai\AiConversationPort;
use App\Application\Ai\LizaReply;

final class FakeConversations implements AiConversationPort
{
    /** @var array<string, array{taskType: string, requestId: int, actorId: int, chatId: string, parentId: string}> */
    public array $items = [];

    public function create(string $taskType, int $requestId, int $documentVersionId, int $actorId, LizaReply $reply): string
    {
        $id = str_pad((string) (count($this->items) + 1), 32, '0', STR_PAD_LEFT);
        $this->items[$id] = compact('taskType', 'requestId', 'actorId')
            + ['chatId' => $reply->chatId, 'parentId' => $reply->messageId];
        return $id;
    }
}

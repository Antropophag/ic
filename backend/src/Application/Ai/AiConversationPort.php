<?php

declare(strict_types=1);

namespace App\Application\Ai;

interface AiConversationPort
{
    public function create(string $taskType, int $requestId, int $documentVersionId, int $actorId, LizaReply $reply): string;
}

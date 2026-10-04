<?php

declare(strict_types=1);

namespace App\Application\Ai;

final readonly class LizaReply
{
    public function __construct(
        public string $chatId,
        public string $messageId,
        public string $content,
    ) {
    }
}

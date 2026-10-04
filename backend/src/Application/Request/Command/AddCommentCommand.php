<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

final readonly class AddCommentCommand
{
    public function __construct(
        public int $requestId,
        public int $actorId,
        public string $body,
    ) {
    }
}

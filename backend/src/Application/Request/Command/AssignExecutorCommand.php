<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

final readonly class AssignExecutorCommand
{
    public function __construct(
        public int $requestId,
        public int $executorId,
        public int $expectedLockVersion,
        public int $actorId,
    ) {
    }
}

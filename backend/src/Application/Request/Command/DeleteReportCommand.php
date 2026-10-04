<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

final readonly class DeleteReportCommand
{
    public function __construct(
        public int $requestId,
        public int $expectedLockVersion,
        public int $actorId,
        public string $reason,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

final readonly class DecideSecurityCommand
{
    public function __construct(
        public int $requestId,
        public int $actorId,
        public string $decision,
        public ?string $reason,
        public int $expectedLockVersion,
    ) {
    }
}

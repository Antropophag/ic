<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

final readonly class AssignExpertCommand
{
    public function __construct(
        public ExpertAssignmentAction $action,
        public int $requestId,
        public int $expertId,
        public int $expectedLockVersion,
        public int $actorId,
    ) {
    }

    public static function claim(int $requestId, int $expectedLockVersion, int $actorId): self
    {
        return new self(ExpertAssignmentAction::Claim, $requestId, $actorId, $expectedLockVersion, $actorId);
    }
}

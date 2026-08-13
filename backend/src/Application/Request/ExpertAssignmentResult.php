<?php

declare(strict_types=1);

namespace App\Application\Request;

final readonly class ExpertAssignmentResult
{
    public function __construct(
        public int $id,
        public int $requestId,
        public int $expertId,
        public int $assignedBy,
        public string $assignedAt,
        public int $lockVersion,
    ) {
    }

    /** @return array{id: int, requestId: int, expertId: int, assignedBy: int, assignedAt: string, lockVersion: int} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}

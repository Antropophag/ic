<?php

declare(strict_types=1);

namespace App\Application\Request;

final readonly class ExecutorAssignmentResult
{
    public function __construct(
        public int $id,
        public int $requestId,
        public int $executorId,
        public int $assignedBy,
        public string $assignedAt,
        public int $lockVersion,
    ) {
    }

    /** @return array{id: int, requestId: int, executorId: int, assignedBy: int, assignedAt: string, lockVersion: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'requestId' => $this->requestId,
            'executorId' => $this->executorId,
            'assignedBy' => $this->assignedBy,
            'assignedAt' => $this->assignedAt,
            'lockVersion' => $this->lockVersion,
        ];
    }
}

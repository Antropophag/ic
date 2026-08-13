<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\ExecutorAssignmentSnapshot;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;

interface ExecutorAssignmentGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function assignmentSnapshotForUpdate(int $requestId): ?ExecutorAssignmentSnapshot;

    public function executorActiveState(int $executorId): ?bool;

    public function isActiveUser(int $userId): bool;

    /** @return list<Role> */
    public function rolesFor(int $userId): array;

    public function isCurrentExecutor(int $requestId, int $executorId): bool;

    public function assignmentTimestamp(): string;

    public function persistRequestAssignmentVersion(
        int $requestId,
        RequestStatus $status,
        int $currentLockVersion,
        int $nextLockVersion,
        string $assignedAt,
    ): bool;

    public function closeCurrentExecutorAssignment(int $requestId, string $assignedAt): void;

    public function createExecutorAssignment(
        int $requestId,
        int $executorId,
        int $actorId,
        string $assignedAt,
    ): int;

    public function recordExecutorAssigned(
        int $requestId,
        int $executorId,
        int $assignmentId,
        int $actorId,
        int $lockVersion,
        string $assignedAt,
    ): void;

    public function enqueueExecutorAssigned(
        int $requestId,
        int $executorId,
        RequestStatus $status,
    ): void;

    public function recordRejectedAssignment(
        int $requestId,
        int $executorId,
        int $actorId,
        string $ruleId,
    ): void;
}

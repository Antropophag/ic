<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\Command\ExpertAssignmentAction;
use App\Application\Request\ExpertAssignmentSnapshot;
use App\Domain\Request\Role;

interface ExpertAssignmentGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function assignmentSnapshotForUpdate(int $requestId, int $actorId): ?ExpertAssignmentSnapshot;

    public function expertActiveState(int $expertId): ?bool;

    public function isActiveUser(int $userId): bool;

    /** @return list<Role> */
    public function rolesFor(int $userId): array;

    public function assignmentTimestamp(): string;

    public function persistRequestAssignmentVersion(
        int $requestId,
        int $currentLockVersion,
        int $nextLockVersion,
        string $assignedAt,
    ): bool;

    public function closeCurrentExpertAssignment(int $requestId, string $assignedAt): void;

    public function createExpertAssignment(int $requestId, int $expertId, int $actorId, string $assignedAt): int;

    public function recordExpertAssigned(
        ExpertAssignmentAction $action,
        int $requestId,
        int $expertId,
        int $assignmentId,
        int $actorId,
        int $lockVersion,
        string $assignedAt,
    ): void;

    public function enqueueExpertReassigned(int $requestId, int $expertId): void;

    public function recordRejectedAssignment(int $requestId, int $expertId, int $actorId, string $ruleId): void;
}

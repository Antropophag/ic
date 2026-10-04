<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\ExpertAssignmentAction;
use App\Application\Request\ExpertAssignmentSnapshot;
use App\Application\Request\Port\ExpertAssignmentGateway;
use App\Domain\Request\Role;

final class InMemoryExpertAssignmentGateway implements ExpertAssignmentGateway
{
    /** @var list<Role> */
    public array $actorRoles = [Role::Expert];
    /** @var list<Role> */
    public array $expertRoles = [Role::Expert];
    public bool $actorActive = true;
    public bool $expertActive = true;
    public bool $expertExists = true;
    public bool $updateSucceeds = true;
    public bool $closed = false;
    public bool $notified = false;
    /** @var array<string, mixed>|null */
    public ?array $audit = null;
    /** @var array<string, mixed>|null */
    public ?array $deniedAudit = null;

    public function __construct(public ?ExpertAssignmentSnapshot $snapshot)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    public function assignmentSnapshotForUpdate(int $requestId, int $actorId): ?ExpertAssignmentSnapshot
    {
        return $this->snapshot;
    }

    public function expertActiveState(int $expertId): ?bool
    {
        return $this->expertExists ? $this->expertActive : null;
    }

    public function isActiveUser(int $userId): bool
    {
        return $this->actorActive;
    }

    public function rolesFor(int $userId): array
    {
        return $userId === 23 ? $this->actorRoles : $this->expertRoles;
    }

    public function assignmentTimestamp(): string
    {
        return '2026-08-14 12:00:00.000000';
    }

    public function persistRequestAssignmentVersion(
        int $requestId,
        int $currentLockVersion,
        int $nextLockVersion,
        string $assignedAt,
    ): bool {
        return $this->updateSucceeds;
    }

    public function closeCurrentExpertAssignment(int $requestId, string $assignedAt): void
    {
        $this->closed = true;
    }

    public function createExpertAssignment(int $requestId, int $expertId, int $actorId, string $assignedAt): int
    {
        return 91;
    }

    public function recordExpertAssigned(
        ExpertAssignmentAction $action,
        int $requestId,
        int $expertId,
        int $assignmentId,
        int $actorId,
        int $lockVersion,
        string $assignedAt,
    ): void {
        $this->audit = compact('action', 'requestId', 'expertId', 'assignmentId', 'actorId', 'lockVersion');
    }

    public function enqueueExpertReassigned(int $requestId, int $expertId): void
    {
        $this->notified = true;
    }

    public function recordRejectedAssignment(int $requestId, int $expertId, int $actorId, string $ruleId): void
    {
        $this->deniedAudit = compact('requestId', 'expertId', 'actorId', 'ruleId');
    }
}

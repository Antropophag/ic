<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ExecutorAssignmentSnapshot;
use App\Application\Request\Port\ExecutorAssignmentGateway;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;

final class InMemoryExecutorAssignmentGateway implements ExecutorAssignmentGateway
{
    /** @var list<Role> */
    public array $actorRoles = [Role::IcManager];
    /** @var list<Role> */
    public array $executorRoles = [Role::IcExecutor];
    public bool $actorActive = true;
    public bool $executorActive = true;
    public bool $executorExists = true;
    public bool $currentExecutor = false;
    public bool $updateSucceeds = true;
    public bool $closed = false;
    /** @var array<string, mixed>|null */
    public ?array $audit = null;
    /** @var array<string, mixed>|null */
    public ?array $notification = null;
    /** @var array<string, mixed>|null */
    public ?array $deniedAudit = null;

    public function __construct(public ?ExecutorAssignmentSnapshot $snapshot)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    public function assignmentSnapshotForUpdate(int $requestId): ?ExecutorAssignmentSnapshot
    {
        return $this->snapshot;
    }

    public function executorActiveState(int $executorId): ?bool
    {
        return $this->executorExists ? $this->executorActive : null;
    }

    public function isActiveUser(int $userId): bool
    {
        return $userId === 23 ? $this->actorActive : $this->executorActive;
    }

    public function rolesFor(int $userId): array
    {
        return $userId === 23 ? $this->actorRoles : $this->executorRoles;
    }

    public function isCurrentExecutor(int $requestId, int $executorId): bool
    {
        return $this->currentExecutor;
    }

    public function assignmentTimestamp(): string
    {
        return '2026-08-14 12:00:00.000000';
    }

    public function persistRequestAssignmentVersion(
        int $requestId,
        RequestStatus $status,
        int $currentLockVersion,
        int $nextLockVersion,
        string $assignedAt,
    ): bool {
        return $this->updateSucceeds;
    }

    public function closeCurrentExecutorAssignment(int $requestId, string $assignedAt): void
    {
        $this->closed = true;
    }

    public function createExecutorAssignment(
        int $requestId,
        int $executorId,
        int $actorId,
        string $assignedAt,
    ): int {
        return 91;
    }

    public function recordExecutorAssigned(
        int $requestId,
        int $executorId,
        int $assignmentId,
        int $actorId,
        int $lockVersion,
        string $assignedAt,
    ): void {
        $this->audit = compact('requestId', 'executorId', 'assignmentId', 'actorId', 'lockVersion', 'assignedAt');
    }

    public function enqueueExecutorAssigned(int $requestId, int $executorId, RequestStatus $status): void
    {
        $this->notification = compact('requestId', 'executorId', 'status');
    }

    public function recordRejectedAssignment(
        int $requestId,
        int $executorId,
        int $actorId,
        string $ruleId,
    ): void {
        $this->deniedAudit = compact('requestId', 'executorId', 'actorId', 'ruleId');
    }
}

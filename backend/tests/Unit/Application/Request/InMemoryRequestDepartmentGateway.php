<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ChangeRequestDepartmentResult;
use App\Application\Request\DepartmentChangeSnapshot;
use App\Application\Request\Port\RequestDepartmentGateway;

final class InMemoryRequestDepartmentGateway implements RequestDepartmentGateway
{
    public ?string $persistedDepartment = null;
    public ?int $persistedLockVersion = null;
    /** @var array{requestId: int, actorId: int, previous: DepartmentChangeSnapshot, department: string, ruleId: string}|null */
    public ?array $audit = null;

    public function __construct(
        private readonly bool $administrator,
        private readonly ?DepartmentChangeSnapshot $snapshot,
    ) {
    }

    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    public function lockAdministratorRole(int $actorId): bool
    {
        return $this->administrator;
    }

    public function departmentChangeSnapshotForUpdate(
        int $requestId,
        int $actorId,
    ): ?DepartmentChangeSnapshot {
        return $this->snapshot;
    }

    public function departmentChangeTimestamp(): string
    {
        return '2026-08-13 18:00:00.000000';
    }

    public function persistDepartmentChange(
        int $requestId,
        string $department,
        int $lockVersion,
        string $changedAt,
    ): void {
        $this->persistedDepartment = $department;
        $this->persistedLockVersion = $lockVersion;
    }

    public function recordDepartmentChanged(
        int $requestId,
        int $actorId,
        DepartmentChangeSnapshot $previous,
        string $department,
        string $ruleId,
        string $changedAt,
    ): void {
        $this->audit = compact('requestId', 'actorId', 'previous', 'department', 'ruleId');
    }

    public function departmentChangeResult(int $requestId): ChangeRequestDepartmentResult
    {
        return new ChangeRequestDepartmentResult([
            'id' => $requestId,
            'department' => $this->persistedDepartment,
            'lock_version' => $this->persistedLockVersion,
        ]);
    }
}

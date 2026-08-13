<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\ChangeRequestDepartmentResult;
use App\Application\Request\DepartmentChangeSnapshot;

interface RequestDepartmentGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function lockAdministratorRole(int $actorId): bool;

    public function departmentChangeSnapshotForUpdate(int $requestId, int $actorId): ?DepartmentChangeSnapshot;

    public function departmentChangeTimestamp(): string;

    public function persistDepartmentChange(
        int $requestId,
        string $department,
        int $lockVersion,
        string $changedAt,
    ): void;

    public function recordDepartmentChanged(
        int $requestId,
        int $actorId,
        DepartmentChangeSnapshot $previous,
        string $department,
        string $ruleId,
        string $changedAt,
    ): void;

    public function departmentChangeResult(int $requestId): ChangeRequestDepartmentResult;
}

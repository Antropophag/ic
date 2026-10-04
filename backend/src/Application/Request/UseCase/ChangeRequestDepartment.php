<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\ChangeRequestDepartmentResult;
use App\Application\Request\Command\ChangeRequestDepartmentCommand;
use App\Application\Request\Port\RequestDepartmentGateway;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestDepartmentChangePolicy;
use App\Domain\Request\RequestNotFound;

final readonly class ChangeRequestDepartment
{
    private const RULE_ID = 'REQ-011';

    public function __construct(
        private RequestDepartmentGateway $gateway,
        private RequestDepartmentChangePolicy $policy = new RequestDepartmentChangePolicy(),
    ) {
    }

    public function execute(ChangeRequestDepartmentCommand $command): ChangeRequestDepartmentResult
    {
        $this->gateway->transactional(function () use ($command): void {
            $administrator = $this->gateway->lockAdministratorRole($command->actorId);
            $snapshot = $this->gateway->departmentChangeSnapshotForUpdate($command->requestId, $command->actorId);
            if ($snapshot === null) {
                throw new RequestNotFound('Request not found');
            }

            $this->policy->assertCanChange($administrator, $snapshot->actorActive);
            if ($snapshot->lockVersion !== $command->expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }

            $department = trim($command->department);
            $changedAt = $this->gateway->departmentChangeTimestamp();
            $this->gateway->persistDepartmentChange(
                $command->requestId,
                $department,
                $snapshot->lockVersion + 1,
                $changedAt,
            );
            $this->gateway->recordDepartmentChanged(
                $command->requestId,
                $command->actorId,
                $snapshot,
                $department,
                self::RULE_ID,
                $changedAt,
            );
        });

        return $this->gateway->departmentChangeResult($command->requestId);
    }
}

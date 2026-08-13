<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\Command\AssignExecutorCommand;
use App\Application\Request\ExecutorAssignmentResult;
use App\Application\Request\Port\ExecutorAssignmentGateway;
use App\Domain\Request\AssignmentPolicy;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestStatus;

final readonly class AssignExecutor
{
    public function __construct(
        private ExecutorAssignmentGateway $gateway,
        private AssignmentPolicy $policy = new AssignmentPolicy(),
    ) {
    }

    public function execute(AssignExecutorCommand $command): ExecutorAssignmentResult
    {
        return $this->gateway->transactional(function () use ($command): ExecutorAssignmentResult {
            $snapshot = $this->gateway->assignmentSnapshotForUpdate($command->requestId);
            if ($snapshot === null) {
                throw new AssignmentTargetNotFound('Request not found');
            }
            if (
                !in_array($snapshot->status, [
                    RequestStatus::Registered,
                    RequestStatus::InProgress,
                    RequestStatus::Suspended,
                ], true)
                || $snapshot->lockVersion !== $command->expectedLockVersion
            ) {
                throw new ConcurrentRequestModification();
            }
            $executorActive = $this->gateway->executorActiveState($command->executorId);
            if ($executorActive === null) {
                throw new AssignmentTargetNotFound('Executor not found');
            }

            $this->policy->assertCanAssign(
                $this->gateway->rolesFor($command->actorId),
                $executorActive,
                $this->gateway->rolesFor($command->executorId),
                $this->gateway->isActiveUser($command->actorId),
                $this->gateway->isCurrentExecutor($command->requestId, $command->executorId),
            );

            $assignedAt = $this->gateway->assignmentTimestamp();
            $nextLockVersion = $snapshot->lockVersion + 1;
            if (
                !$this->gateway->persistRequestAssignmentVersion(
                    $command->requestId,
                    $snapshot->status,
                    $snapshot->lockVersion,
                    $nextLockVersion,
                    $assignedAt,
                )
            ) {
                throw new ConcurrentRequestModification();
            }
            $this->gateway->closeCurrentExecutorAssignment($command->requestId, $assignedAt);
            $assignmentId = $this->gateway->createExecutorAssignment(
                $command->requestId,
                $command->executorId,
                $command->actorId,
                $assignedAt,
            );
            $this->gateway->recordExecutorAssigned(
                $command->requestId,
                $command->executorId,
                $assignmentId,
                $command->actorId,
                $nextLockVersion,
                $assignedAt,
            );
            $this->gateway->enqueueExecutorAssigned($command->requestId, $command->executorId, $snapshot->status);

            return new ExecutorAssignmentResult(
                $assignmentId,
                $command->requestId,
                $command->executorId,
                $command->actorId,
                $assignedAt,
                $nextLockVersion,
            );
        });
    }

    public function recordRejected(AssignExecutorCommand $command, string $ruleId): void
    {
        $this->gateway->recordRejectedAssignment(
            $command->requestId,
            $command->executorId,
            $command->actorId,
            $ruleId,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\Command\AssignExpertCommand;
use App\Application\Request\Command\ExpertAssignmentAction;
use App\Application\Request\ExpertAssignmentResult;
use App\Application\Request\Port\ExpertAssignmentGateway;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\ExpertAssignmentDenied;
use App\Domain\Request\ExpertAssignmentPolicy;

final readonly class AssignExpert
{
    public function __construct(
        private ExpertAssignmentGateway $gateway,
        private ExpertAssignmentPolicy $policy = new ExpertAssignmentPolicy(),
    ) {
    }

    public function execute(AssignExpertCommand $command): ExpertAssignmentResult
    {
        return $this->gateway->transactional(function () use ($command): ExpertAssignmentResult {
            $snapshot = $this->gateway->assignmentSnapshotForUpdate($command->requestId, $command->actorId);
            if ($snapshot === null) {
                throw new AssignmentTargetNotFound('Request not found');
            }
            if ($snapshot->lockVersion !== $command->expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }

            $actorActive = $this->gateway->isActiveUser($command->actorId);
            $actorRoles = $this->gateway->rolesFor($command->actorId);
            if ($command->action === ExpertAssignmentAction::Claim) {
                if ($command->expertId !== $command->actorId) {
                    throw new ExpertAssignmentDenied('WF-010');
                }
                $this->policy->assertCanClaim(
                    $snapshot->status,
                    $actorActive,
                    $actorRoles,
                    $snapshot->actorIsCurrentExpert,
                );
            } else {
                $targetActive = $this->gateway->expertActiveState($command->expertId);
                if ($targetActive === null) {
                    throw new AssignmentTargetNotFound('Expert not found');
                }
                $this->policy->assertCanReassign(
                    $snapshot->status,
                    $actorActive,
                    $actorRoles,
                    $snapshot->actorIsCurrentExpert,
                    $command->actorId === $command->expertId,
                    $targetActive,
                    $this->gateway->rolesFor($command->expertId),
                );
            }

            return $this->persist($command, $snapshot->lockVersion);
        });
    }

    public function recordRejected(AssignExpertCommand $command, string $ruleId): void
    {
        $this->gateway->recordRejectedAssignment(
            $command->requestId,
            $command->expertId,
            $command->actorId,
            $ruleId,
        );
    }

    private function persist(AssignExpertCommand $command, int $currentLockVersion): ExpertAssignmentResult
    {
        $assignedAt = $this->gateway->assignmentTimestamp();
        $nextLockVersion = $currentLockVersion + 1;
        if (
            !$this->gateway->persistRequestAssignmentVersion(
                $command->requestId,
                $currentLockVersion,
                $nextLockVersion,
                $assignedAt,
            )
        ) {
            throw new ConcurrentRequestModification();
        }
        $this->gateway->closeCurrentExpertAssignment($command->requestId, $assignedAt);
        $assignmentId = $this->gateway->createExpertAssignment(
            $command->requestId,
            $command->expertId,
            $command->actorId,
            $assignedAt,
        );
        $this->gateway->recordExpertAssigned(
            $command->action,
            $command->requestId,
            $command->expertId,
            $assignmentId,
            $command->actorId,
            $nextLockVersion,
            $assignedAt,
        );
        if ($command->action === ExpertAssignmentAction::Reassign) {
            $this->gateway->enqueueExpertReassigned($command->requestId, $command->expertId);
        }

        return new ExpertAssignmentResult(
            $assignmentId,
            $command->requestId,
            $command->expertId,
            $command->actorId,
            $assignedAt,
            $nextLockVersion,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\Command\CancelRequestCommand;
use App\Application\Request\Port\RequestCancellationGateway;
use App\Application\Request\RequestLifecycleResult;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RejectPolicy;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestWorkflow;
use App\Domain\Request\WithdrawPolicy;

final readonly class CancelRequest
{
    public function __construct(
        private RequestCancellationGateway $gateway,
        private RequestWorkflow $workflow = new RequestWorkflow(),
        private RejectPolicy $rejectPolicy = new RejectPolicy(),
        private WithdrawPolicy $withdrawPolicy = new WithdrawPolicy(),
    ) {
    }

    public function execute(CancelRequestCommand $command): RequestLifecycleResult
    {
        return $this->gateway->transactional(function () use ($command): RequestLifecycleResult {
            $snapshot = $this->gateway->cancellationSnapshotForUpdate($command->requestId, $command->action);
            if ($snapshot === null) {
                throw new RequestNotFound('Request not found');
            }
            if (
                $snapshot->lockVersion !== $command->expectedLockVersion
                || !$this->workflow->allowsCancellationTransition($snapshot->status, $command->action)
                || (
                $command->action === RequestAction::Withdraw && $snapshot->reviewedBySecurity
                )
            ) {
                throw new ConcurrentRequestModification();
            }

            $roles = $this->gateway->rolesFor($command->actorId);
            $isActive = $this->gateway->isActiveUser($command->actorId);
            if ($command->action === RequestAction::Reject) {
                $this->rejectPolicy->assertCanReject($roles, $isActive);
            } else {
                $this->withdrawPolicy->assertCanWithdraw($snapshot->initiatorId === $command->actorId, $isActive);
            }

            $targetStatus = $this->workflow->transition($snapshot->status, $command->action, $roles);
            $ruleId = $command->action === RequestAction::Reject ? 'WF-006' : 'WF-007';
            $nextLockVersion = $snapshot->lockVersion + 1;
            $changedAt = $this->gateway->cancellationTimestamp();
            if (
                !$this->gateway->persistCancellation(
                    $command->requestId,
                    $snapshot->status,
                    $targetStatus,
                    $snapshot->lockVersion,
                    $nextLockVersion,
                    $changedAt,
                )
            ) {
                throw new ConcurrentRequestModification();
            }

            $this->gateway->recordCancellationTransition(
                $command->requestId,
                $command->actorId,
                $snapshot->status,
                $targetStatus,
                $command->action,
                $ruleId,
                $command->reason,
                $changedAt,
            );
            $this->gateway->recordCancellationAudit(
                $command->requestId,
                $command->actorId,
                $snapshot->status,
                $targetStatus,
                $command->action,
                $nextLockVersion,
                $ruleId,
                $command->reason,
                $changedAt,
            );
            $this->gateway->enqueueCancellationNotifications($command->requestId, $command->action);

            return new RequestLifecycleResult(
                $command->requestId,
                $targetStatus,
                $nextLockVersion,
                $command->action,
                $changedAt,
            );
        });
    }

    public function recordRejected(CancelRequestCommand $command, string $ruleId): void
    {
        $this->gateway->recordRejectedCancellation(
            $command->requestId,
            $command->actorId,
            $command->action,
            $ruleId,
        );
    }
}

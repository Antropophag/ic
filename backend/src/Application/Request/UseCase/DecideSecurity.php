<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\Command\DecideSecurityCommand;
use App\Application\Request\Port\SecurityDecisionGateway;
use App\Application\Request\SecurityDecisionResult;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\SecurityDecisionPolicy;

final readonly class DecideSecurity
{
    public function __construct(
        private SecurityDecisionGateway $gateway,
        private SecurityDecisionPolicy $policy = new SecurityDecisionPolicy(),
    ) {
    }

    public function execute(DecideSecurityCommand $command): SecurityDecisionResult
    {
        return $this->gateway->transactional(function () use ($command): SecurityDecisionResult {
            $snapshot = $this->gateway->decisionSnapshotForUpdate($command->requestId, $command->actorId);
            if ($snapshot === null) {
                throw new RequestNotFound('Request not found');
            }
            $targetStatus = $this->policy->targetStatus(
                $snapshot->status,
                $command->decision,
                $command->reason,
                $snapshot->actorIsActive,
                $snapshot->actorRoles,
            );
            if ($snapshot->lockVersion !== $command->expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }
            $opinionId = $this->gateway->currentUncheckedOpinionIdForUpdate($command->requestId);
            if ($opinionId === null) {
                throw new \RuntimeException('Current expert opinion not found or already checked.');
            }

            $decidedAt = $this->gateway->decisionTimestamp();
            $reason = $command->decision === 'return' ? $command->reason : null;
            $this->gateway->recordSecurityCheck(
                $command->requestId,
                $opinionId,
                $command->actorId,
                $command->decision,
                $reason,
                $decidedAt,
            );
            $nextLockVersion = $command->expectedLockVersion + 1;
            if (
                !$this->gateway->persistDecision(
                    $command->requestId,
                    $targetStatus,
                    $command->expectedLockVersion,
                    $nextLockVersion,
                    $decidedAt,
                )
            ) {
                throw new ConcurrentRequestModification();
            }
            $this->gateway->recordDecision(
                $command->requestId,
                $command->actorId,
                $command->decision,
                $command->reason,
                $targetStatus,
                $decidedAt,
            );
            $this->gateway->enqueueDecisionNotification($command->requestId, $command->decision, $command->reason);

            return new SecurityDecisionResult(
                $command->requestId,
                $command->decision,
                $targetStatus,
                $nextLockVersion,
            );
        });
    }

    public function recordRejected(DecideSecurityCommand $command, string $ruleId): void
    {
        $this->gateway->recordRejectedDecision($command->requestId, $command->actorId, $ruleId);
    }
}

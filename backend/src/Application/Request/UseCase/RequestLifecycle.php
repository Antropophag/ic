<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\Command\RequestLifecycleCommand;
use App\Application\Request\Port\RequestLifecycleGateway;
use App\Application\Request\RequestLifecycleResult;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestWorkflow;
use App\Domain\Request\StartRequestPolicy;
use App\Domain\Request\SuspendResumePolicy;

final readonly class RequestLifecycle
{
    public function __construct(
        private RequestLifecycleGateway $gateway,
        private RequestWorkflow $workflow = new RequestWorkflow(),
        private StartRequestPolicy $startPolicy = new StartRequestPolicy(),
        private SuspendResumePolicy $suspendResumePolicy = new SuspendResumePolicy(),
    ) {
    }

    public function execute(RequestLifecycleCommand $command): RequestLifecycleResult
    {
        return $this->gateway->transactional(function () use ($command): RequestLifecycleResult {
            $snapshot = $this->gateway->lifecycleSnapshotForUpdate($command->requestId);
            if ($snapshot === null) {
                throw new RequestNotFound('Request not found');
            }
            if ($snapshot->lockVersion !== $command->expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }

            $roles = $this->gateway->rolesFor($command->actorId);
            $isCurrentExecutor = $this->gateway->isCurrentExecutor($command->requestId, $command->actorId);
            $isActiveUser = $this->gateway->isActiveUser($command->actorId);
            $this->assertAuthorized($command->action, $roles, $isCurrentExecutor, $isActiveUser);
            $targetStatus = $this->workflow->transition($snapshot->status, $command->action, $roles);
            $nextLockVersion = $snapshot->lockVersion + 1;
            $changedAt = $this->gateway->lifecycleTimestamp();
            $persisted = $this->gateway->persistLifecycleTransition(
                $command->requestId,
                $snapshot->status,
                $targetStatus,
                $snapshot->lockVersion,
                $nextLockVersion,
                $changedAt,
            );
            if (!$persisted) {
                throw new ConcurrentRequestModification();
            }

            $ruleId = $command->action === RequestAction::Start ? 'WF-004' : 'WF-005';
            $this->gateway->recordLifecycleTransition(
                $command->requestId,
                $command->actorId,
                $snapshot->status,
                $targetStatus,
                $command->action,
                $ruleId,
                $command->reason,
                $changedAt,
            );
            $this->gateway->recordLifecycleAudit(
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

            return new RequestLifecycleResult(
                $command->requestId,
                $targetStatus,
                $nextLockVersion,
                $command->action,
                $changedAt,
            );
        });
    }

    public function recordRejected(RequestLifecycleCommand $command, string $ruleId): void
    {
        $this->gateway->recordRejectedLifecycle(
            $command->requestId,
            $command->actorId,
            $command->action,
            $ruleId,
        );
    }

    /** @param list<\App\Domain\Request\Role> $roles */
    private function assertAuthorized(
        RequestAction $action,
        array $roles,
        bool $isCurrentExecutor,
        bool $isActiveUser,
    ): void {
        if ($action === RequestAction::Start) {
            $this->startPolicy->assertCanStart($roles, $isCurrentExecutor, $isActiveUser);
            return;
        }

        if ($action === RequestAction::Suspend) {
            $this->suspendResumePolicy->assertCanSuspend($roles, $isCurrentExecutor, $isActiveUser);
            return;
        }

        $this->suspendResumePolicy->assertCanResume($roles, $isCurrentExecutor, $isActiveUser);
    }
}

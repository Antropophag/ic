<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Port\RequestCancellationGateway;
use App\Application\Request\RequestCancellationSnapshot;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;

final class InMemoryRequestCancellationGateway implements RequestCancellationGateway
{
    public bool $persisted = false;
    public bool $transitionRecorded = false;
    public bool $auditRecorded = false;
    public bool $notificationsEnqueued = false;
    public ?RequestAction $rejectedAction = null;
    public ?RequestAction $snapshotAction = null;

    /** @param list<Role> $roles */
    public function __construct(
        private readonly ?RequestCancellationSnapshot $snapshot,
        private readonly array $roles = [],
        private readonly bool $active = true,
    ) {
    }

    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    public function cancellationSnapshotForUpdate(
        int $requestId,
        RequestAction $action,
    ): ?RequestCancellationSnapshot {
        $this->snapshotAction = $action;
        return $this->snapshot;
    }

    public function rolesFor(int $actorId): array
    {
        return $this->roles;
    }

    public function isActiveUser(int $actorId): bool
    {
        return $this->active;
    }

    public function cancellationTimestamp(): string
    {
        return '2026-08-14 12:00:00';
    }

    public function persistCancellation(
        int $requestId,
        RequestStatus $from,
        RequestStatus $to,
        int $currentLockVersion,
        int $nextLockVersion,
        string $changedAt,
    ): bool {
        return $this->persisted = true;
    }

    public function recordCancellationTransition(
        int $requestId,
        int $actorId,
        RequestStatus $from,
        RequestStatus $to,
        RequestAction $action,
        string $ruleId,
        string $reason,
        string $changedAt,
    ): void {
        $this->transitionRecorded = true;
    }

    public function recordCancellationAudit(
        int $requestId,
        int $actorId,
        RequestStatus $from,
        RequestStatus $to,
        RequestAction $action,
        int $lockVersion,
        string $ruleId,
        string $reason,
        string $changedAt,
    ): void {
        $this->auditRecorded = true;
    }

    public function enqueueCancellationNotifications(int $requestId, RequestAction $action): void
    {
        $this->notificationsEnqueued = true;
    }

    public function recordRejectedCancellation(
        int $requestId,
        int $actorId,
        RequestAction $action,
        string $ruleId,
    ): void {
        $this->rejectedAction = $action;
    }
}

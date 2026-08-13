<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\RequestCancellationSnapshot;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;

interface RequestCancellationGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function cancellationSnapshotForUpdate(
        int $requestId,
        RequestAction $action,
    ): ?RequestCancellationSnapshot;

    /** @return list<Role> */
    public function rolesFor(int $actorId): array;

    public function isActiveUser(int $actorId): bool;

    public function cancellationTimestamp(): string;

    public function persistCancellation(
        int $requestId,
        RequestStatus $from,
        RequestStatus $to,
        int $currentLockVersion,
        int $nextLockVersion,
        string $changedAt,
    ): bool;

    public function recordCancellationTransition(
        int $requestId,
        int $actorId,
        RequestStatus $from,
        RequestStatus $to,
        RequestAction $action,
        string $ruleId,
        string $reason,
        string $changedAt,
    ): void;

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
    ): void;

    public function enqueueCancellationNotifications(int $requestId, RequestAction $action): void;

    public function recordRejectedCancellation(
        int $requestId,
        int $actorId,
        RequestAction $action,
        string $ruleId,
    ): void;
}

<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\RequestLifecycleSnapshot;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;

interface RequestLifecycleGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function lifecycleSnapshotForUpdate(int $requestId): ?RequestLifecycleSnapshot;

    /** @return list<Role> */
    public function rolesFor(int $actorId): array;

    public function isCurrentExecutor(int $requestId, int $actorId): bool;

    public function isActiveUser(int $actorId): bool;

    public function lifecycleTimestamp(): string;

    public function persistLifecycleTransition(
        int $requestId,
        RequestStatus $from,
        RequestStatus $to,
        int $currentLockVersion,
        int $nextLockVersion,
        string $changedAt,
    ): bool;

    public function recordLifecycleTransition(
        int $requestId,
        int $actorId,
        RequestStatus $from,
        RequestStatus $to,
        RequestAction $action,
        string $ruleId,
        ?string $reason,
        string $changedAt,
    ): void;

    public function recordLifecycleAudit(
        int $requestId,
        int $actorId,
        RequestStatus $from,
        RequestStatus $to,
        RequestAction $action,
        int $lockVersion,
        string $ruleId,
        ?string $reason,
        string $changedAt,
    ): void;

    public function recordRejectedLifecycle(
        int $requestId,
        int $actorId,
        RequestAction $action,
        string $ruleId,
    ): void;
}

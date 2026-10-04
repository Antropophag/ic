<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Port\RequestLifecycleGateway;
use App\Application\Request\RequestLifecycleSnapshot;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;

final class InMemoryRequestLifecycleGateway implements RequestLifecycleGateway
{
    /** @var array<string, mixed>|null */
    public ?array $transition = null;
    /** @var array<string, mixed>|null */
    public ?array $audit = null;
    /** @var array<string, mixed>|null */
    public ?array $rejectedAudit = null;

    /** @param list<Role> $roles */
    public function __construct(
        private readonly ?RequestLifecycleSnapshot $snapshot,
        private readonly array $roles,
        private readonly bool $currentExecutor = false,
        private readonly bool $active = true,
        private readonly bool $updateSucceeds = true,
    ) {
    }

    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    public function lifecycleSnapshotForUpdate(int $requestId): ?RequestLifecycleSnapshot
    {
        return $this->snapshot;
    }

    public function rolesFor(int $actorId): array
    {
        return $this->roles;
    }

    public function isCurrentExecutor(int $requestId, int $actorId): bool
    {
        return $this->currentExecutor;
    }

    public function isActiveUser(int $actorId): bool
    {
        return $this->active;
    }

    public function lifecycleTimestamp(): string
    {
        return '2026-08-13 20:00:00.000000';
    }

    public function persistLifecycleTransition(
        int $requestId,
        RequestStatus $from,
        RequestStatus $to,
        int $currentLockVersion,
        int $nextLockVersion,
        string $changedAt,
    ): bool {
        return $this->updateSucceeds;
    }

    public function recordLifecycleTransition(
        int $requestId,
        int $actorId,
        RequestStatus $from,
        RequestStatus $to,
        RequestAction $action,
        string $ruleId,
        ?string $reason,
        string $changedAt,
    ): void {
        $this->transition = compact('requestId', 'actorId', 'from', 'to', 'action', 'ruleId', 'reason', 'changedAt');
    }

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
    ): void {
        $this->audit = compact('requestId', 'actorId', 'from', 'to', 'action', 'lockVersion', 'ruleId', 'reason', 'changedAt');
    }

    public function recordRejectedLifecycle(int $requestId, int $actorId, RequestAction $action, string $ruleId): void
    {
        $this->rejectedAudit = compact('requestId', 'actorId', 'action', 'ruleId');
    }
}

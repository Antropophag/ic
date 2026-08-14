<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\SecurityDecisionSnapshot;
use App\Domain\Request\RequestStatus;

interface SecurityDecisionGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function decisionSnapshotForUpdate(int $requestId, int $actorId): ?SecurityDecisionSnapshot;

    public function currentUncheckedOpinionIdForUpdate(int $requestId): ?int;

    public function recordSecurityCheck(
        int $requestId,
        int $opinionId,
        int $actorId,
        string $decision,
        ?string $reason,
        string $decidedAt,
    ): void;

    public function persistDecision(
        int $requestId,
        RequestStatus $targetStatus,
        int $expectedLockVersion,
        int $nextLockVersion,
        string $decidedAt,
    ): bool;

    public function recordDecision(
        int $requestId,
        int $actorId,
        string $decision,
        ?string $reason,
        RequestStatus $targetStatus,
        string $decidedAt,
    ): void;

    public function enqueueDecisionNotification(int $requestId, string $decision, ?string $reason): void;

    public function decisionTimestamp(): string;

    public function recordRejectedDecision(int $requestId, int $actorId, string $ruleId): void;
}

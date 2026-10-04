<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class SecurityDecisionPolicy
{
    /** @param list<Role> $actorRoles */
    public function targetStatus(
        RequestStatus $status,
        string $decision,
        ?string $reason,
        bool $actorIsActive,
        array $actorRoles,
    ): RequestStatus {
        if ($decision === 'approve' && trim((string) $reason) !== '') {
            throw new InvalidSecurityDecisionReason();
        }
        if (!$actorIsActive) {
            throw new SecurityDecisionDenied('AUTH-003');
        }
        if ($status !== RequestStatus::SecurityReview) {
            throw new SecurityDecisionDenied('SEC-001');
        }
        if (!in_array(Role::SecurityOfficer, $actorRoles, true)) {
            throw new SecurityDecisionDenied('SEC-001');
        }
        if ($decision === 'return' && trim((string) $reason) === '') {
            throw new SecurityDecisionDenied('SEC-003');
        }

        return match ($decision) {
            'approve' => RequestStatus::Completed,
            'return' => RequestStatus::InProgress,
            default => throw new SecurityDecisionDenied('SEC-001'),
        };
    }
}

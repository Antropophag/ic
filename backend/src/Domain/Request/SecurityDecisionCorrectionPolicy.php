<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class SecurityDecisionCorrectionPolicy
{
    /** @param list<Role> $roles */
    public function assertAllowed(bool $active, array $roles, bool $archived, ?string $currentDecision, string $decision): void
    {
        if (!$active || !in_array(Role::Administrator, $roles, true) || $archived) {
            throw new SecurityDecisionDenied('SEC-006');
        }
        if (!in_array($currentDecision, ['approve', 'decline'], true) || !in_array($decision, ['approve', 'decline'], true) || $currentDecision === $decision) {
            throw new SecurityDecisionDenied('SEC-006');
        }
    }
}

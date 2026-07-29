<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class ExpertAssignmentPolicy
{
    /** @param list<Role> $actorRoles */
    public function assertCanClaim(
        RequestStatus $status,
        bool $actorIsActive,
        array $actorRoles,
        bool $actorIsCurrentExpert,
    ): void {
        if (!$actorIsActive) {
            throw new ExpertAssignmentDenied('AUTH-003');
        }
        if (!in_array(Role::Expert, $actorRoles, true) || $actorIsCurrentExpert) {
            throw new ExpertAssignmentDenied('WF-010');
        }
        if ($status !== RequestStatus::OpinionPreparation) {
            throw new ExpertAssignmentDenied('DOC-005');
        }
    }

    /**
     * @param list<Role> $actorRoles
     * @param list<Role> $targetRoles
     */
    public function assertCanReassign(
        RequestStatus $status,
        bool $actorIsActive,
        array $actorRoles,
        bool $actorIsCurrentExpert,
        bool $isSelfTarget,
        bool $targetIsActive,
        array $targetRoles,
    ): void {
        if (!$actorIsActive) {
            throw new ExpertAssignmentDenied('AUTH-003');
        }
        if (!in_array(Role::Expert, $actorRoles, true) || !$actorIsCurrentExpert) {
            throw new ExpertAssignmentDenied('WF-011');
        }
        if ($status !== RequestStatus::OpinionPreparation) {
            throw new ExpertAssignmentDenied('DOC-005');
        }
        if ($isSelfTarget || !$targetIsActive || !in_array(Role::Expert, $targetRoles, true)) {
            throw new ExpertAssignmentDenied('WF-011');
        }
    }
}

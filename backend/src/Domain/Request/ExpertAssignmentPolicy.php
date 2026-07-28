<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class ExpertAssignmentPolicy
{
    /**
     * @param list<Role> $actorRoles
     * @param list<Role> $expertRoles
     */
    public function assertCanAssign(
        RequestStatus $status,
        array $actorRoles,
        bool $actorIsActive,
        array $expertRoles,
        bool $expertIsActive,
    ): void {
        if (!$actorIsActive) {
            throw new ExpertAssignmentDenied('AUTH-003');
        }
        if (
            !in_array(Role::IcManager, $actorRoles, true)
            && !in_array(Role::LaboratoryManager, $actorRoles, true)
        ) {
            throw new ExpertAssignmentDenied('WF-010');
        }
        if ($status !== RequestStatus::OpinionPreparation) {
            throw new ExpertAssignmentDenied('DOC-005');
        }
        if (!$expertIsActive || !in_array(Role::Expert, $expertRoles, true)) {
            throw new ExpertAssignmentDenied('WF-011');
        }
    }
}

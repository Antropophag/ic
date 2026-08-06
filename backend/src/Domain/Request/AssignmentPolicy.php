<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class AssignmentPolicy
{
    /**
     * @param list<Role> $actorRoles
     * @param list<Role> $executorRoles
     */
    public function assertCanAssign(
        array $actorRoles,
        bool $executorActive,
        array $executorRoles,
        bool $actorActive,
        bool $isCurrentExecutor = false,
    ): void {
        if (!$actorActive) {
            throw new AssignmentDenied('AUTH-003');
        }
        if (!$this->hasAny($actorRoles, [Role::IcManager, Role::LaboratoryManager])) {
            throw new AssignmentDenied('WF-001');
        }

        if (!$executorActive || !$this->hasAny($executorRoles, [Role::IcExecutor])) {
            throw new AssignmentDenied('WF-002');
        }

        if ($isCurrentExecutor) {
            throw new AssignmentDenied('WF-013');
        }
    }

    /**
     * @param list<Role> $actual
     * @param list<Role> $required
     */
    private function hasAny(array $actual, array $required): bool
    {
        foreach ($actual as $role) {
            if (in_array($role, $required, true)) {
                return true;
            }
        }

        return false;
    }
}

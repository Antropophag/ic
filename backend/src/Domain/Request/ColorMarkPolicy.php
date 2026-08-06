<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class ColorMarkPolicy
{
    /** @param list<Role> $actorRoles */
    public function assertCanSetColor(array $actorRoles, bool $actorActive): void
    {
        if (!$actorActive) {
            throw new ColorMarkDenied('AUTH-003');
        }

        if (!$this->hasAny($actorRoles, [Role::IcManager, Role::LaboratoryManager])) {
            throw new ColorMarkDenied('WF-009');
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

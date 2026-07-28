<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class RequestCreationPolicy
{
    /** @param list<Role> $initiatorRoles */
    public function assertCanCreate(array $initiatorRoles, bool $initiatorActive): void
    {
        if (!$initiatorActive) {
            throw new RequestCreationDenied('AUTH-003');
        }

        if ($this->hasAny($initiatorRoles, [Role::IcExecutor, Role::IcManager, Role::LaboratoryManager])) {
            throw new RequestCreationDenied('REQ-001');
        }
    }

    /**
     * @param list<Role> $actual
     * @param list<Role> $forbidden
     */
    private function hasAny(array $actual, array $forbidden): bool
    {
        foreach ($actual as $role) {
            if (in_array($role, $forbidden, true)) {
                return true;
            }
        }

        return false;
    }
}

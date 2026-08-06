<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Request\Role;

final class RoleManagementPolicy
{
    /** @param list<Role> $actorRoles */
    public function assertCanManage(bool $actorActive, array $actorRoles): void
    {
        if (!$actorActive) {
            throw new RoleManagementDenied('AUTH-003');
        }
        if (!in_array(Role::Administrator, $actorRoles, true)) {
            throw new RoleManagementDenied('AUTH-007');
        }
    }
}

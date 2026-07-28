<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class StartRequestPolicy
{
    /** @param list<Role> $actorRoles */
    public function assertCanStart(array $actorRoles, bool $isCurrentExecutor): void
    {
        foreach ($actorRoles as $role) {
            if (in_array($role, [Role::IcManager, Role::LaboratoryManager], true)) {
                return;
            }
        }

        if ($isCurrentExecutor && in_array(Role::IcExecutor, $actorRoles, true)) {
            return;
        }

        throw new StartDenied('WF-004');
    }
}

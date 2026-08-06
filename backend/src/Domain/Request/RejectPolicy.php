<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class RejectPolicy
{
    /** @param list<Role> $actorRoles */
    public function assertCanReject(array $actorRoles, bool $isActorActive): void
    {
        if (!$isActorActive) {
            throw new RejectDenied('AUTH-003');
        }

        foreach ($actorRoles as $role) {
            if (in_array($role, [Role::IcManager, Role::LaboratoryManager], true)) {
                return;
            }
        }

        throw new RejectDenied('WF-006');
    }
}

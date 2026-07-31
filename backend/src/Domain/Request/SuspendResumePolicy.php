<?php

declare(strict_types=1);

namespace App\Domain\Request;

// WF-005: приостановить и возобновить работу может исполнитель либо
// руководитель — то же правило для обоих переходов, поэтому один класс
// (по аналогии с SecurityDecisionPolicy, обслуживающим approve и return).
final class SuspendResumePolicy
{
    /** @param list<Role> $actorRoles */
    public function assertCanSuspend(array $actorRoles, bool $isCurrentExecutor, bool $isActorActive): void
    {
        $this->assert($actorRoles, $isCurrentExecutor, $isActorActive);
    }

    /** @param list<Role> $actorRoles */
    public function assertCanResume(array $actorRoles, bool $isCurrentExecutor, bool $isActorActive): void
    {
        $this->assert($actorRoles, $isCurrentExecutor, $isActorActive);
    }

    /** @param list<Role> $actorRoles */
    private function assert(array $actorRoles, bool $isCurrentExecutor, bool $isActorActive): void
    {
        if (!$isActorActive) {
            throw new SuspendResumeDenied('AUTH-003');
        }

        foreach ($actorRoles as $role) {
            if (in_array($role, [Role::IcManager, Role::LaboratoryManager], true)) {
                return;
            }
        }

        if ($isCurrentExecutor && in_array(Role::IcExecutor, $actorRoles, true)) {
            return;
        }

        throw new SuspendResumeDenied('WF-005');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class CloseRequestPolicy
{
    /** @param list<Role> $roles */
    public function assertCanClose(
        RequestStatus $status,
        bool $hasReport,
        bool $active,
        array $roles,
        bool $expertiseRequired,
        bool $securityRequired,
    ): void {
        if (!$active) {
            throw new RequestRouteDenied('AUTH-003');
        }
        if (!in_array(Role::IcManager, $roles, true) && !in_array(Role::LaboratoryManager, $roles, true)) {
            throw new RequestRouteDenied('WF-015');
        }
        if ($status !== RequestStatus::InProgress || !$hasReport) {
            throw new RequestRouteDenied('WF-015');
        }
        if ($securityRequired && !$expertiseRequired) {
            throw new RequestRouteDenied('WF-016');
        }
    }
}

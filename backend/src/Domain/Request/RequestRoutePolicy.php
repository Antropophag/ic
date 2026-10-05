<?php

declare(strict_types=1);

namespace App\Domain\Request;

/** Route selection freezes at the first report, including a subsequently deleted report. */
final class RequestRoutePolicy
{
    /** @param list<Role> $roles */
    public function assertCanChoose(
        RequestStatus $status,
        ?RequestRoute $currentRoute,
        RequestRoute $route,
        bool $hasReportHistory,
        bool $active,
        array $roles,
    ): void {
        $this->assertManager($active, $roles);
        if (
            !in_array($status, [RequestStatus::Registered, RequestStatus::InProgress, RequestStatus::Suspended], true)
            || ($currentRoute === null && $status !== RequestStatus::Registered)
            || $hasReportHistory
            || $currentRoute === $route
        ) {
            throw new RequestRouteDenied('WF-014');
        }
    }

    /** @param list<Role> $roles */
    public function assertCanComplete(
        RequestStatus $status,
        ?RequestRoute $route,
        bool $hasReport,
        bool $active,
        array $roles,
    ): void {
        $this->assertManager($active, $roles);
        if ($status !== RequestStatus::InProgress || $route !== RequestRoute::Act || !$hasReport) {
            throw new RequestRouteDenied('WF-015');
        }
    }

    /** @param list<Role> $roles */
    private function assertManager(bool $active, array $roles): void
    {
        if (!$active) {
            throw new RequestRouteDenied('AUTH-003');
        }
        if (!in_array(Role::IcManager, $roles, true) && !in_array(Role::LaboratoryManager, $roles, true)) {
            throw new RequestRouteDenied('WF-014');
        }
    }
}

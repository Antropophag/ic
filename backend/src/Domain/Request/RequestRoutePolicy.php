<?php

declare(strict_types=1);

namespace App\Domain\Request;

/** WF-014/WF-016: publication or completion permanently freezes route settings. */
final class RequestRoutePolicy
{
    /** @param list<Role> $roles */
    public function assertCanChoose(
        RequestStatus $status,
        ?RequestRoute $currentRoute,
        RequestRoute $route,
        bool $hasBoundaryHistory,
        bool $active,
        array $roles,
        bool $currentSecurityReviewRequired = true,
        bool $securityReviewRequired = true,
    ): void {
        $this->assertManager($active, $roles);
        if (
            !in_array($status, [RequestStatus::Registered, RequestStatus::InProgress, RequestStatus::Suspended, RequestStatus::OpinionPreparation], true)
            || ($currentRoute === null && $status !== RequestStatus::Registered)
            || $hasBoundaryHistory
            || ($currentRoute === $route && ($route === RequestRoute::Act || $currentSecurityReviewRequired === $securityReviewRequired))
        ) {
            throw new RequestRouteDenied('WF-014');
        }
    }

    /** A route change preserves suspension; resuming later applies the new route to the report. */
    public function statusAfterSelection(RequestStatus $status, RequestRoute $route, bool $hasReport): RequestStatus
    {
        if (!in_array($status, [RequestStatus::InProgress, RequestStatus::OpinionPreparation], true)) {
            return $status;
        }
        return $route === RequestRoute::Protocol && $hasReport ? RequestStatus::OpinionPreparation : RequestStatus::InProgress;
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

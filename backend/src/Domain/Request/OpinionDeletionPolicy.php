<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class OpinionDeletionPolicy
{
    /** @param list<Role> $roles */
    public function assertCanDelete(
        bool $active,
        array $roles,
        bool $archived,
        RequestStatus $status,
        ?string $route,
        bool $hasReport,
        bool $hasOpinion,
    ): void {
        if (
            !$active || $archived
            || (!in_array(Role::IcManager, $roles, true) && !in_array(Role::Expert, $roles, true))
            || !in_array($status, [RequestStatus::OpinionPreparation, RequestStatus::SecurityReview, RequestStatus::Completed], true)
            || $route !== 'protocol' || !$hasReport || !$hasOpinion
        ) {
            throw new OpinionDenied('DOC-014');
        }
    }
}

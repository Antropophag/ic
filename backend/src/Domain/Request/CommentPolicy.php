<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class CommentPolicy
{
    public function assertCanAdd(RequestStatus $status): void
    {
        if (
            !in_array(
                $status,
                [
                    RequestStatus::Registered,
                    RequestStatus::InProgress,
                    RequestStatus::Suspended,
                    RequestStatus::OpinionPreparation,
                    RequestStatus::SecurityReview,
                ],
                true,
            )
        ) {
            throw new CommentDenied();
        }
    }
}

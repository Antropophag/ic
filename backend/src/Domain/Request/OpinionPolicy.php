<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class OpinionPolicy
{
    public function assertCanPublish(RequestStatus $status, bool $actorIsActive, bool $isCurrentExpert): void
    {
        if (!$actorIsActive) {
            throw new OpinionDenied('AUTH-003');
        }
        if ($status !== RequestStatus::OpinionPreparation) {
            throw new OpinionDenied('DOC-007');
        }
        if (!$isCurrentExpert) {
            throw new OpinionDenied('DOC-005');
        }
    }
}

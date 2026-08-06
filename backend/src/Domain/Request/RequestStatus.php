<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum RequestStatus: string
{
    case Registered = 'registered';
    case InProgress = 'in_progress';
    case Suspended = 'suspended';
    case OpinionPreparation = 'opinion_preparation';
    case SecurityReview = 'security_review';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Completed, self::Rejected, self::Withdrawn => true,
            default => false,
        };
    }
}

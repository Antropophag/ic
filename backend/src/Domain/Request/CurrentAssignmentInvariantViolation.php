<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class CurrentAssignmentInvariantViolation extends \RuntimeException
{
    public function __construct(int $requestId, string $assignmentType, int $assignmentCount)
    {
        parent::__construct(
            "Request {$requestId} has {$assignmentCount} current {$assignmentType} assignments; expected at most one",
        );
    }
}

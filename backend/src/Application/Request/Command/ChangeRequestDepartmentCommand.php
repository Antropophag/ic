<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

final readonly class ChangeRequestDepartmentCommand
{
    public function __construct(
        public int $requestId,
        public string $department,
        public int $expectedLockVersion,
        public int $actorId,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;

final readonly class SecurityDecisionSnapshot
{
    /** @param list<Role> $actorRoles */
    public function __construct(
        public RequestStatus $status,
        public int $lockVersion,
        public bool $actorIsActive,
        public array $actorRoles,
    ) {
    }
}

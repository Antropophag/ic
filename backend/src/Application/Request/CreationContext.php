<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\Role;

final readonly class CreationContext
{
    /** @param list<Role> $initiatorRoles */
    public function __construct(
        public array $initiatorRoles,
        public bool $initiatorActive,
    ) {
    }
}

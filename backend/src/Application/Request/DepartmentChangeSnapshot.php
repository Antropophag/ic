<?php

declare(strict_types=1);

namespace App\Application\Request;

final readonly class DepartmentChangeSnapshot
{
    public function __construct(
        public string $departmentName,
        public ?string $departmentExternalId,
        public int $lockVersion,
        public bool $actorActive,
    ) {
    }
}

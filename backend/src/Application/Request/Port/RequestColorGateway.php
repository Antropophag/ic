<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Domain\Request\RequestColor;
use App\Domain\Request\Role;

interface RequestColorGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function lockVersionForUpdate(int $requestId): ?int;

    /** @return list<Role> */
    public function rolesFor(int $actorId): array;

    public function isActiveUser(int $actorId): bool;

    public function persistColorChange(int $requestId, RequestColor $color, int $lockVersion): void;

    public function recordColorMarked(int $requestId, int $actorId, RequestColor $color, string $ruleId): void;
}

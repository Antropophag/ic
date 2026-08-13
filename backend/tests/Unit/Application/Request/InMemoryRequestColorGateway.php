<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Port\RequestColorGateway;
use App\Domain\Request\RequestColor;
use App\Domain\Request\Role;

final class InMemoryRequestColorGateway implements RequestColorGateway
{
    public ?RequestColor $persistedColor = null;
    public ?int $persistedLockVersion = null;
    /** @var array{requestId: int, actorId: int, color: RequestColor, ruleId: string}|null */
    public ?array $audit = null;

    /** @param list<Role> $roles */
    public function __construct(
        private readonly ?int $lockVersion,
        private readonly array $roles,
        private readonly bool $active,
    ) {
    }

    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    public function lockVersionForUpdate(int $requestId): ?int
    {
        return $this->lockVersion;
    }

    public function rolesFor(int $actorId): array
    {
        return $this->roles;
    }

    public function isActiveUser(int $actorId): bool
    {
        return $this->active;
    }

    public function persistColorChange(int $requestId, RequestColor $color, int $lockVersion): void
    {
        $this->persistedColor = $color;
        $this->persistedLockVersion = $lockVersion;
    }

    public function recordColorMarked(int $requestId, int $actorId, RequestColor $color, string $ruleId): void
    {
        $this->audit = compact('requestId', 'actorId', 'color', 'ruleId');
    }
}

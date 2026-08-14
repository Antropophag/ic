<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\Command\CreateRequestCommand;
use App\Application\Request\CreationContext;

interface RequestCreationGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function creationContext(int $initiatorId): CreationContext;

    public function departmentSnapshotForUpdate(int $initiatorId): ?string;

    public function allocateNumber(): int;

    public function creationTimestamp(): string;

    public function insertRequest(
        CreateRequestCommand $command,
        string $department,
        int $number,
        string $createdAt,
    ): int;

    public function recordCreation(int $requestId, int $actorId, string $createdAt): void;

    public function enqueueCreationNotifications(int $requestId, int $number, string $productName, int $initiatorId): void;

    /** @return array<string, mixed> */
    public function createdRequest(int $requestId): array;

    public function recordRejectedCreation(int $actorId, string $ruleId): void;
}

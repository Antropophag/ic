<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\AddCommentResult;
use App\Domain\Request\RequestStatus;

interface RequestCommentGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function statusForActiveActorForUpdate(int $requestId, int $actorId): ?RequestStatus;

    public function commentTimestamp(): string;

    public function persistComment(int $requestId, int $actorId, string $body, string $createdAt): int;

    public function recordCommentAdded(int $requestId, int $actorId, int $commentId, string $createdAt): void;

    public function enqueueCommentNotifications(int $requestId, int $actorId): void;

    public function commentResult(int $commentId): AddCommentResult;
}

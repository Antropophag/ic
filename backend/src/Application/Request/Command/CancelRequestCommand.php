<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

use App\Domain\Request\RequestAction;

final readonly class CancelRequestCommand
{
    public function __construct(
        public int $requestId,
        public int $expectedLockVersion,
        public int $actorId,
        public RequestAction $action,
        public string $reason,
    ) {
        if (!in_array($action, [RequestAction::Reject, RequestAction::Withdraw], true)) {
            throw new \InvalidArgumentException("Unsupported cancellation action {$action->value}");
        }
    }
}

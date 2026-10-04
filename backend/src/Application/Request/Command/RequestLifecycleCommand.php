<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

use App\Domain\Request\RequestAction;

final readonly class RequestLifecycleCommand
{
    public function __construct(
        public int $requestId,
        public int $expectedLockVersion,
        public int $actorId,
        public RequestAction $action,
        public ?string $reason = null,
    ) {
        if (!in_array($action, [RequestAction::Start, RequestAction::Suspend, RequestAction::Resume], true)) {
            throw new \InvalidArgumentException("Unsupported lifecycle action {$action->value}");
        }
    }
}

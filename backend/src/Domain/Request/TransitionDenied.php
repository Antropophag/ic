<?php

declare(strict_types=1);

namespace App\Domain\Request;

use DomainException;

final class TransitionDenied extends DomainException
{
    public function __construct(
        public readonly string $ruleId,
        RequestStatus $from,
        RequestAction $action,
    ) {
        parent::__construct("Action {$action->value} is not allowed from {$from->value} ({$ruleId})");
    }
}

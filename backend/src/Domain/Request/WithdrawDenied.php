<?php

declare(strict_types=1);

namespace App\Domain\Request;

use DomainException;

final class WithdrawDenied extends DomainException
{
    public function __construct(public readonly string $ruleId)
    {
        parent::__construct("Request withdrawal is not allowed ({$ruleId})");
    }
}

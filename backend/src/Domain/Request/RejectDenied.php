<?php

declare(strict_types=1);

namespace App\Domain\Request;

use DomainException;

final class RejectDenied extends DomainException
{
    public function __construct(public readonly string $ruleId)
    {
        parent::__construct("Request rejection is not allowed ({$ruleId})");
    }
}

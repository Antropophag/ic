<?php

declare(strict_types=1);

namespace App\Domain\Request;

use DomainException;

final class RequestCreationDenied extends DomainException
{
    public function __construct(public readonly string $ruleId)
    {
        parent::__construct("Request creation is not allowed ({$ruleId})");
    }
}

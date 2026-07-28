<?php

declare(strict_types=1);

namespace App\Domain\Request;

use DomainException;

final class ColorMarkDenied extends DomainException
{
    public function __construct(public readonly string $ruleId)
    {
        parent::__construct("Setting the request color mark is not allowed ({$ruleId})");
    }
}

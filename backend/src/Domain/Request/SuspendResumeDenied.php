<?php

declare(strict_types=1);

namespace App\Domain\Request;

use DomainException;

final class SuspendResumeDenied extends DomainException
{
    public function __construct(public readonly string $ruleId)
    {
        parent::__construct("Suspend/resume is not allowed ({$ruleId})");
    }
}

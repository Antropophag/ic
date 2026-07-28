<?php

declare(strict_types=1);

namespace App\Domain\Request;

use DomainException;

final class ConcurrentRequestModification extends DomainException
{
    public function __construct(public readonly string $ruleId = 'WF-003')
    {
        parent::__construct("Request was modified concurrently ({$ruleId})");
    }
}

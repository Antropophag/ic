<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class SecurityDecisionDenied extends \DomainException
{
    public function __construct(public readonly string $ruleId)
    {
        parent::__construct($ruleId);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class SecurityDecisionConflict extends \DomainException
{
    public function __construct(public readonly string $ruleId = 'WF-003')
    {
        parent::__construct("Security decision conflicts with the current workflow state ({$ruleId})");
    }
}

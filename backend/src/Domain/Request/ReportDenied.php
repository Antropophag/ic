<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class ReportDenied extends \DomainException
{
    public function __construct(public readonly string $ruleId = 'DOC-001')
    {
        parent::__construct($ruleId);
    }
}

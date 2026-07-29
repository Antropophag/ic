<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class ReportDeletionDenied extends \DomainException
{
    public function __construct(public readonly string $ruleId = 'DOC-011')
    {
        parent::__construct($ruleId);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Request;

use DomainException;

final class CommentDenied extends DomainException
{
    public function __construct(public readonly string $ruleId = 'COM-001')
    {
        parent::__construct('Comments are not allowed for the current request status.');
    }
}

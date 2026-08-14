<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class InvalidSecurityDecisionReason extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Reason is not allowed for an approved security decision.');
    }
}

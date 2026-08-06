<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use DomainException;

final class AuthenticationDenied extends DomainException
{
    public function __construct(public readonly string $ruleId = 'AUTH-001')
    {
        parent::__construct('Invalid login or password');
    }
}

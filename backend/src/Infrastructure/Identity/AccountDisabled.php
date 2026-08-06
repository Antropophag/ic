<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use DomainException;

final class AccountDisabled extends DomainException
{
    public function __construct(public readonly string $ruleId = 'AUTH-003')
    {
        parent::__construct('Local profile is disabled');
    }
}

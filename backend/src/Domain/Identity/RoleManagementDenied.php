<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final class RoleManagementDenied extends \DomainException
{
    public function __construct(public readonly string $ruleId)
    {
        parent::__construct("Role management denied by rule {$ruleId}");
    }
}

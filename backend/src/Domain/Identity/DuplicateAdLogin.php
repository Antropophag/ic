<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final class DuplicateAdLogin extends \DomainException
{
    public function __construct(public readonly string $adLogin)
    {
        parent::__construct("A user with ad_login '{$adLogin}' already exists");
    }
}

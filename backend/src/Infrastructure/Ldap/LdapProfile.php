<?php

declare(strict_types=1);

namespace App\Infrastructure\Ldap;

final readonly class LdapProfile
{
    public function __construct(
        public string $login,
        public string $displayName,
        public ?string $email,
        public ?string $department,
        public ?string $position,
    ) {
    }
}

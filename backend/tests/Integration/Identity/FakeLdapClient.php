<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Ldap\LdapClient;
use App\Infrastructure\Ldap\LdapProfile;

final class FakeLdapClient implements LdapClient
{
    public function __construct(private readonly ?LdapProfile $profile)
    {
    }

    public function authenticate(string $login, string $password): ?LdapProfile
    {
        return $this->profile !== null && $this->profile->login === $login ? $this->profile : null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Ldap\LdapClient;
use App\Infrastructure\Ldap\LdapProfile;

final class FakeLdapClient implements LdapClient
{
    /** @var list<array{login: string, password: string}> */
    public array $calls = [];

    public function __construct(private readonly ?LdapProfile $profile)
    {
    }

    public function authenticate(string $login, string $password): ?LdapProfile
    {
        $this->calls[] = ['login' => $login, 'password' => $password];
        return $this->profile !== null && $this->profile->login === $login ? $this->profile : null;
    }
}

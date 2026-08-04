<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

final readonly class LoginAuthenticator
{
    public function __construct(
        private BreakGlassAuthenticator $breakGlass,
        private LdapAuthenticator $ldap,
    ) {
    }

    /** @return array{id: int, displayName: string} */
    public function authenticate(
        string $login,
        string $password,
        string $ip,
        string $userAgent,
    ): array {
        $this->breakGlass->reportInvalidConfiguration($ip, $userAgent);
        if ($this->breakGlass->handles($login)) {
            return $this->breakGlass->authenticate($login, $password, $ip, $userAgent);
        }
        if (hash_equals(BreakGlassAuthenticator::TECHNICAL_LOGIN, $login)) {
            throw new AuthenticationDenied('AUTH-006');
        }

        return $this->ldap->authenticate($login, $password);
    }
}

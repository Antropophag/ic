<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

final readonly class LoginAuthenticator
{
    public function __construct(
        private BreakGlassAuthenticator $breakGlass,
        private LdapAuthenticator $ldap,
        private UserActivityRecorder $activity,
    ) {
    }

    /** @return array{id: int, displayName: string} */
    public function authenticate(
        string $login,
        string $password,
        string $ip,
        string $userAgent,
    ): array {
        if ($this->breakGlass->handles($login)) {
            $this->breakGlass->reportInvalidConfiguration($ip, $userAgent);
            $result = $this->breakGlass->authenticate($login, $password, $ip, $userAgent);
        } elseif (hash_equals(BreakGlassAuthenticator::TECHNICAL_LOGIN, $login)) {
            $this->breakGlass->reportInvalidConfiguration($ip, $userAgent);
            throw new AuthenticationDenied('AUTH-006');
        } else {
            $result = $this->ldap->authenticate($login, $password);
        }
        $this->activity->recordLogin($result['id']);

        return $result;
    }
}

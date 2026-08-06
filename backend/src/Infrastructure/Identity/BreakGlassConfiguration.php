<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

final readonly class BreakGlassConfiguration
{
    private const LOGIN_PATTERN = '/^[A-Za-z0-9._-]+$/D';
    private ?string $login;
    private ?string $passwordHash;

    public function __construct(
        ?string $login,
        ?string $passwordHash,
    ) {
        $this->login = $login === '' ? null : $login;
        $this->passwordHash = $passwordHash === '' ? null : $passwordHash;
    }

    public static function fromEnvironment(): self
    {
        $login = getenv('BREAK_GLASS_LOGIN');
        $passwordHash = getenv('BREAK_GLASS_PASSWORD_HASH');

        return new self(
            is_string($login) ? $login : null,
            is_string($passwordHash) ? $passwordHash : null,
        );
    }

    public function isDisabled(): bool
    {
        return $this->login === null && $this->passwordHash === null;
    }

    public function isValid(): bool
    {
        return $this->errorCode() === null && !$this->isDisabled();
    }

    public function matches(string $login): bool
    {
        return $this->login !== null && hash_equals($this->login, $login);
    }

    public function verify(string $password): bool
    {
        return $this->isValid()
            && $this->passwordHash !== null
            && password_verify($password, $this->passwordHash);
    }

    public function errorCode(): ?string
    {
        if ($this->isDisabled()) {
            return null;
        }
        if ($this->login === null) {
            return 'missing_login';
        }
        if ($this->passwordHash === null) {
            return 'missing_password_hash';
        }
        if (
            strlen($this->login) > 128
            || preg_match(self::LOGIN_PATTERN, $this->login) !== 1
            || hash_equals(BreakGlassAuthenticator::TECHNICAL_LOGIN, $this->login)
        ) {
            return 'invalid_login';
        }

        $hashInformation = password_get_info($this->passwordHash);
        return ($hashInformation['algoName'] ?? 'unknown') === 'unknown' ? 'invalid_password_hash' : null;
    }
}

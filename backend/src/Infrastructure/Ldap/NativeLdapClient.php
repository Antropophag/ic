<?php

declare(strict_types=1);

namespace App\Infrastructure\Ldap;

final class NativeLdapClient implements LdapClient
{
    private const NETWORK_TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $domain,
        private readonly string $baseDn,
        private readonly bool $useTls,
    ) {
    }

    public function authenticate(string $login, string $password): ?LdapProfile
    {
        // AD отклоняет bind с пустым паролем как анонимный (успешный), а не
        // как отказ — пустой пароль должен быть отклонён раньше, чем дойдёт
        // до ldap_bind().
        if ($login === '' || $password === '') {
            return null;
        }

        $connection = @ldap_connect($this->host, $this->port);
        if ($connection === false) {
            throw new LdapConnectionException("Cannot connect to LDAP server {$this->host}:{$this->port}");
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, self::NETWORK_TIMEOUT_SECONDS);

        if ($this->useTls && !@ldap_start_tls($connection)) {
            throw new LdapConnectionException('LDAP StartTLS negotiation failed: ' . ldap_error($connection));
        }

        // Простой bind под именем самого пользователя — так же, как для
        // Bitrix24, права намеренно не шире необходимого (это же соединение
        // и учётные данные ниже используются для чтения собственного
        // профиля, отдельный сервисный аккаунт не заводится).
        $bound = @ldap_bind($connection, "{$login}@{$this->domain}", $password);
        if (!$bound) {
            $errorCode = ldap_errno($connection);
            // 0x31 (invalid credentials) и его варианты — обычный отказ
            // логина/пароля, а не сбой инфраструктуры.
            if (in_array($errorCode, [0x31, 0x32, 0x34], true)) {
                return null;
            }
            throw new LdapConnectionException('LDAP bind failed: ' . ldap_error($connection));
        }

        $profile = $this->fetchProfile($connection, $login);
        ldap_unbind($connection);

        return $profile;
    }

    /** @param \LDAP\Connection $connection */
    private function fetchProfile($connection, string $login): ?LdapProfile
    {
        $filter = sprintf('(sAMAccountName=%s)', ldap_escape($login, '', LDAP_ESCAPE_FILTER));
        $result = @ldap_search($connection, $this->baseDn, $filter, ['displayName', 'mail', 'department', 'title']);
        if ($result === false) {
            throw new LdapConnectionException('LDAP search failed: ' . ldap_error($connection));
        }

        $entries = ldap_get_entries($connection, $result);
        if ($entries === false || (int) $entries['count'] === 0) {
            return null;
        }

        $entry = $entries[0];

        return new LdapProfile(
            $login,
            $this->attribute($entry, 'displayname') ?? $login,
            $this->attribute($entry, 'mail'),
            $this->attribute($entry, 'department'),
            $this->attribute($entry, 'title'),
        );
    }

    /** @param array<string, mixed> $entry */
    private function attribute(array $entry, string $name): ?string
    {
        return isset($entry[$name][0]) && is_string($entry[$name][0]) && $entry[$name][0] !== ''
            ? $entry[$name][0]
            : null;
    }
}

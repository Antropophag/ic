<?php

declare(strict_types=1);

namespace App\Infrastructure\Ldap;

interface LdapClient
{
    /**
     * Проверяет логин/пароль прямым bind к LDAP-серверу (AUTH-001) и
     * возвращает профиль пользователя. Неверные учётные данные — null,
     * а не исключение; сбой соединения с сервером (недоступен, TLS,
     * таймаут) — LdapConnectionException.
     */
    public function authenticate(string $login, string $password): ?LdapProfile;
}

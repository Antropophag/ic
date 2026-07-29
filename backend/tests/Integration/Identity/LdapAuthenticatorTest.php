<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Identity\AccountDisabled;
use App\Infrastructure\Identity\AuthenticationDenied;
use App\Infrastructure\Identity\LdapAuthenticator;
use App\Infrastructure\Ldap\LdapProfile;
use Tests\Integration\IntegrationTestCase;

final class LdapAuthenticatorTest extends IntegrationTestCase
{
    public function testFirstLoginCreatesLocalProfileWithBaseRole(): void
    {
        // AUTH-001/AUTH-002.
        $ldap = new FakeLdapClient(new LdapProfile(
            'ivanov',
            'Иван Иванов',
            'ivanov@shlz.ru',
            'Испытательный центр',
            'Инженер',
        ));
        $authenticator = new LdapAuthenticator($this->db(), $ldap);

        $result = $authenticator->authenticate('ivanov', 'correct-password');

        self::assertSame('Иван Иванов', $result['displayName']);
        $row = $this->db()->createCommand(
            'SELECT display_name AS displayName, email, is_active AS isActive '
            . 'FROM {{%users}} WHERE id = :id',
            [':id' => $result['id']],
        )->queryOne();
        self::assertSame('Иван Иванов', $row['displayName']);
        self::assertSame('ivanov@shlz.ru', $row['email']);
        self::assertSame(1, (int) $row['isActive']);

        $roleCode = $this->scalar(
            'SELECT r.code FROM {{%user_roles}} ur JOIN {{%roles}} r ON r.id = ur.role_id WHERE ur.user_id = :id',
            [':id' => $result['id']],
        );
        self::assertSame('employee', $roleCode);
    }

    public function testInvalidCredentialsAreRejectedWithoutCreatingAProfile(): void
    {
        $ldap = new FakeLdapClient(null);
        $authenticator = new LdapAuthenticator($this->db(), $ldap);

        $this->expectException(AuthenticationDenied::class);
        try {
            $authenticator->authenticate('unknown', 'wrong-password');
        } finally {
            $count = $this->scalar(
                "SELECT COUNT(*) FROM {{%users}} WHERE ad_login = 'unknown'",
            );
            self::assertSame(0, (int) $count);
        }
    }

    public function testDisabledLocalProfileIsRejectedDespiteSuccessfulBind(): void
    {
        // AUTH-003: локальное отключение важнее успешного LDAP bind.
        $login = 'petrov';
        $this->createUser($login, 'Пётр Петров', 'petrov@shlz.ru', false);

        $ldap = new FakeLdapClient(new LdapProfile($login, 'Пётр Петров', 'petrov@shlz.ru', null, null));
        $authenticator = new LdapAuthenticator($this->db(), $ldap);

        $this->expectException(AccountDisabled::class);
        $authenticator->authenticate($login, 'correct-password');
    }

    public function testRepeatedLoginUpdatesProfileButPreservesLocalRolesAndActiveFlag(): void
    {
        // AUTH-004: специальные роли и is_active — локальные, не трогаются
        // повторным входом даже когда AD-профиль изменился.
        $login = 'sidorova';
        $userId = $this->createUser($login, 'Старое имя', 'old@shlz.ru');
        $this->grantRole($userId, 'ic_manager');

        $ldap = new FakeLdapClient(new LdapProfile(
            $login,
            'Анна Сидорова',
            'new@shlz.ru',
            'Лаборатория',
            'Руководитель лаборатории',
        ));
        $authenticator = new LdapAuthenticator($this->db(), $ldap);

        $result = $authenticator->authenticate($login, 'correct-password');
        self::assertSame($userId, $result['id']);
        self::assertSame('Анна Сидорова', $result['displayName']);

        $row = $this->db()->createCommand(
            'SELECT display_name AS displayName, email, is_active AS isActive FROM {{%users}} WHERE id = :id',
            [':id' => $userId],
        )->queryOne();
        self::assertSame('Анна Сидорова', $row['displayName']);
        self::assertSame('new@shlz.ru', $row['email']);
        self::assertSame(1, (int) $row['isActive']);

        $roleCodes = $this->db()->createCommand(
            'SELECT r.code FROM {{%user_roles}} ur JOIN {{%roles}} r ON r.id = ur.role_id WHERE ur.user_id = :id',
            [':id' => $userId],
        )->queryColumn();
        self::assertSame(['ic_manager'], $roleCodes);

        $userCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%users}} WHERE ad_login = :login",
            [':login' => $login],
        );
        self::assertSame(1, (int) $userCount);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Identity\AdministratorBootstrap;
use Tests\Integration\IntegrationTestCase;

final class AdministratorBootstrapTest extends IntegrationTestCase
{
    public function testCreatesAdministratorsAndIsIdempotent(): void
    {
        $bootstrap = new AdministratorBootstrap($this->db());

        $first = $bootstrap->bootstrap([' Bootstrap.Admin.One ', 'bootstrap.admin.two', 'bootstrap.admin.one']);
        $second = $bootstrap->bootstrap(['bootstrap.admin.one', 'bootstrap.admin.two']);

        self::assertSame(['usersCreated' => 2, 'rolesAssigned' => 4], $first);
        self::assertSame(['usersCreated' => 0, 'rolesAssigned' => 0], $second);
        self::assertSame(2, (int) $this->scalar(
            "SELECT COUNT(DISTINCT u.id) FROM {{%users}} u "
            . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id '
            . 'JOIN {{%roles}} r ON r.id = ur.role_id '
            . "WHERE u.ad_login IN ('bootstrap.admin.one', 'bootstrap.admin.two') AND r.code = 'administrator'",
        ));
        self::assertSame(4, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%user_roles}} ur JOIN {{%users}} u ON u.id = ur.user_id "
            . "WHERE u.ad_login IN ('bootstrap.admin.one', 'bootstrap.admin.two')",
        ));
    }

    public function testPreservesExistingProfileDataAndRoles(): void
    {
        $userId = $this->createUser('bootstrap.existing', 'Existing AD profile', 'existing@example.invalid');
        $this->grantRole($userId, 'expert');

        $result = (new AdministratorBootstrap($this->db()))->bootstrap(['bootstrap.existing']);

        self::assertSame(['usersCreated' => 0, 'rolesAssigned' => 2], $result);
        self::assertSame('Existing AD profile', $this->scalar(
            'SELECT display_name FROM {{%users}} WHERE id = :id',
            [':id' => $userId],
        ));
        self::assertSame(3, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%user_roles}} WHERE user_id = :id',
            [':id' => $userId],
        ));
    }

    public function testDisabledAdministratorRollsBackWholeBootstrap(): void
    {
        $this->createUser('disabled.admin', 'Disabled administrator', isActive: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('locally disabled');

        try {
            (new AdministratorBootstrap($this->db()))->bootstrap([
                'new.admin',
                'disabled.admin',
            ]);
        } finally {
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%users}} WHERE ad_login = 'new.admin'",
            ));
        }
    }

    public function testRejectsInvalidLoginBeforeChangingDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            (new AdministratorBootstrap($this->db()))->bootstrap([
                'valid.login',
                'invalid@example.com',
            ]);
        } finally {
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%users}} WHERE ad_login = 'valid.login'",
            ));
        }
    }
}

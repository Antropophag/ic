<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Identity\BreakGlassAuthenticator;
use App\Infrastructure\Identity\BreakGlassConfiguration;
use App\Infrastructure\Identity\BreakGlassIdentityProvisioner;
use Tests\Integration\IntegrationTestCase;

final class BreakGlassIdentityProvisionerTest extends IntegrationTestCase
{
    public function testCreatesSingleTechnicalAdministratorAndIsIdempotent(): void
    {
        $this->removeTechnicalIdentity();
        $provisioner = $this->provisioner();

        self::assertSame(
            ['enabled' => true, 'userCreated' => true, 'roleAssigned' => true, 'rolesRemoved' => 0],
            $provisioner->provision(),
        );
        self::assertSame(
            ['enabled' => true, 'userCreated' => false, 'roleAssigned' => false, 'rolesRemoved' => 0],
            $provisioner->provision(),
        );

        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%users}} WHERE ad_login = :login',
            [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
        ));
        self::assertSame(['administrator'], $this->db()->createCommand(
            'SELECT r.code FROM {{%user_roles}} ur JOIN {{%roles}} r ON r.id = ur.role_id '
            . 'JOIN {{%users}} u ON u.id = ur.user_id WHERE u.ad_login = :login ORDER BY r.code',
            [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
        )->queryColumn());
    }

    public function testRestoresMissingAdministratorRoleWithoutChangingIdentity(): void
    {
        $this->provisioner()->provision();
        $userId = (int) $this->scalar(
            'SELECT id FROM {{%users}} WHERE ad_login = :login',
            [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
        );
        $this->db()->createCommand()->delete('{{%user_roles}}', ['user_id' => $userId])->execute();

        self::assertSame(
            ['enabled' => true, 'userCreated' => false, 'roleAssigned' => true, 'rolesRemoved' => 0],
            $this->provisioner()->provision(),
        );
        self::assertSame($userId, (int) $this->scalar(
            'SELECT id FROM {{%users}} WHERE ad_login = :login',
            [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
        ));
    }

    public function testDisabledConfigurationDoesNotTouchDatabase(): void
    {
        $this->removeTechnicalIdentity();

        self::assertSame(
            ['enabled' => false, 'userCreated' => false, 'roleAssigned' => false, 'rolesRemoved' => 0],
            (new BreakGlassIdentityProvisioner(
                $this->db(),
                new BreakGlassConfiguration(null, null),
            ))->provision(),
        );
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%users}} WHERE ad_login = :login',
            [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
        ));
    }

    public function testProvisioningRemovesRolesOtherThanAdministrator(): void
    {
        $this->provisioner()->provision();
        $userId = (int) $this->scalar(
            'SELECT id FROM {{%users}} WHERE ad_login = :login',
            [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
        );
        $this->grantRole($userId, 'employee');

        self::assertSame(1, $this->provisioner()->provision()['rolesRemoved']);
        self::assertSame(['administrator'], $this->db()->createCommand(
            'SELECT r.code FROM {{%user_roles}} ur JOIN {{%roles}} r ON r.id = ur.role_id '
            . 'WHERE ur.user_id = :id',
            [':id' => $userId],
        )->queryColumn());
    }

    public function testOccupiedReservedLoginFailsWithoutGrantingAdministratorRole(): void
    {
        $this->removeTechnicalIdentity();
        $userId = $this->createUser(BreakGlassAuthenticator::TECHNICAL_LOGIN, 'LDAP collision');

        try {
            $this->provisioner()->provision();
            self::fail('Expected an occupied reserved login to fail provisioning.');
        } catch (\RuntimeException $error) {
            self::assertSame('Reserved break-glass identity login is already in use.', $error->getMessage());
        }
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%user_roles}} ur JOIN {{%roles}} r ON r.id = ur.role_id '
            . "WHERE ur.user_id = :id AND r.code = 'administrator'",
            [':id' => $userId],
        ));
    }

    public function testMissingAdministratorRoleFailsWithoutCreatingIdentity(): void
    {
        $this->removeTechnicalIdentity();
        $this->db()->createCommand()->update('{{%roles}}', ['code' => 'administrator-missing'], [
            'code' => 'administrator',
        ])->execute();

        $this->expectException(\RuntimeException::class);
        try {
            $this->provisioner()->provision();
        } finally {
            self::assertSame(0, (int) $this->scalar(
                'SELECT COUNT(*) FROM {{%users}} WHERE ad_login = :login',
                [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
            ));
        }
    }

    private function provisioner(): BreakGlassIdentityProvisioner
    {
        return new BreakGlassIdentityProvisioner(
            $this->db(),
            new BreakGlassConfiguration('Emergency.Admin', password_hash('test password', PASSWORD_DEFAULT)),
        );
    }

    private function removeTechnicalIdentity(): void
    {
        $userId = $this->scalar(
            'SELECT id FROM {{%users}} WHERE ad_login = :login',
            [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
        );
        if ($userId === false) {
            return;
        }
        $this->db()->createCommand()->delete('{{%user_roles}}', ['user_id' => $userId])->execute();
        $this->db()->createCommand()->delete('{{%users}}', ['id' => $userId])->execute();
    }
}

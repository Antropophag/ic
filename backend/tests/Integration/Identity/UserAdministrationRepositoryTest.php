<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Domain\Identity\DuplicateAdLogin;
use App\Domain\Identity\UserAdministrationTargetNotFound;
use App\Infrastructure\Identity\LdapAuthenticator;
use App\Infrastructure\Identity\BreakGlassAuthenticator;
use App\Infrastructure\Identity\UserAdministrationRepository;
use App\Infrastructure\Ldap\LdapProfile;
use Tests\Integration\IntegrationTestCase;

final class UserAdministrationRepositoryTest extends IntegrationTestCase
{
    public function testCreatePlaceholderCreatesActiveUserWithBaseRole(): void
    {
        // AUTH-002/AUTH-007: pre-provisioning приводит к тому же исходному
        // состоянию, что и обычный первый вход — базовая роль «Сотрудник».
        $admin = $this->createUser('dev.admin1', 'Тестовый администратор 1');
        $repository = new UserAdministrationRepository($this->db());

        $user = $repository->createPlaceholder('kashin', 'Сергей Кашин', $admin);

        self::assertSame('kashin', $user['adLogin']);
        self::assertSame('Сергей Кашин', $user['displayName']);
        self::assertTrue($user['isActive']);
        self::assertSame(['employee'], array_column($user['roles'], 'code'));

        $auditCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE event_type = 'user.pre_provisioned' AND entity_id = :id",
            [':id' => $user['id']],
        );
        self::assertSame(1, (int) $auditCount);
    }

    public function testCreatePlaceholderRejectsDuplicateAdLogin(): void
    {
        $admin = $this->createUser('dev.admin2', 'Тестовый администратор 2');
        $this->createUser('existing.login', 'Уже существующий');
        $repository = new UserAdministrationRepository($this->db());

        $this->expectException(DuplicateAdLogin::class);
        $repository->createPlaceholder('existing.login', 'Кто-то ещё', $admin);
    }

    public function testReservedTechnicalIdentityCannotBeCreatedOrHaveRolesManaged(): void
    {
        $admin = $this->createUser('dev.admin.reserved', 'Тестовый администратор');
        $repository = new UserAdministrationRepository($this->db());

        try {
            $repository->createPlaceholder(
                BreakGlassAuthenticator::TECHNICAL_LOGIN,
                'Collision',
                $admin,
            );
            self::fail('Expected the reserved login to be rejected.');
        } catch (DuplicateAdLogin) {
        }

        $technicalId = $this->createUser(
            BreakGlassAuthenticator::TECHNICAL_LOGIN,
            'Аварийный администратор',
        );
        $roleId = (int) $this->scalar("SELECT id FROM {{%roles}} WHERE code = 'employee'");
        $deniedOperations = 0;
        try {
            $repository->assignRole($technicalId, $roleId, $admin);
            self::fail('Expected assignRole to reject the technical identity.');
        } catch (UserAdministrationTargetNotFound) {
            ++$deniedOperations;
        }
        try {
            $repository->revokeRole($technicalId, $roleId, $admin);
            self::fail('Expected revokeRole to reject the technical identity.');
        } catch (UserAdministrationTargetNotFound) {
            ++$deniedOperations;
        }
        self::assertSame(2, $deniedOperations);
    }

    public function testAssignRoleGrantsRoleAndWritesAudit(): void
    {
        $admin = $this->createUser('dev.admin3', 'Тестовый администратор 3');
        $target = $this->createUser('dev.target1', 'Целевой пользователь 1');
        $roleId = (int) $this->scalar("SELECT id FROM {{%roles}} WHERE code = 'ic_manager'");
        $repository = new UserAdministrationRepository($this->db());

        $roles = $repository->assignRole($target, $roleId, $admin);

        self::assertSame(['ic_manager'], array_column($roles, 'code'));
        $assignedBy = $this->scalar(
            'SELECT assigned_by FROM {{%user_roles}} WHERE user_id = :id AND role_id = :role',
            [':id' => $target, ':role' => $roleId],
        );
        self::assertSame($admin, (int) $assignedBy);
        $auditCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE event_type = 'user.role_assigned' AND entity_id = :id",
            [':id' => $target],
        );
        self::assertSame(1, (int) $auditCount);
    }

    public function testAssigningTheSameRoleTwiceIsIdempotent(): void
    {
        $admin = $this->createUser('dev.admin4', 'Тестовый администратор 4');
        $target = $this->createUser('dev.target2', 'Целевой пользователь 2');
        $roleId = (int) $this->scalar("SELECT id FROM {{%roles}} WHERE code = 'expert'");
        $repository = new UserAdministrationRepository($this->db());

        $repository->assignRole($target, $roleId, $admin);
        $roles = $repository->assignRole($target, $roleId, $admin);

        self::assertSame(['expert'], array_column($roles, 'code'));
        $auditCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE event_type = 'user.role_assigned' AND entity_id = :id",
            [':id' => $target],
        );
        self::assertSame(1, (int) $auditCount);
    }

    public function testAssignRoleToUnknownUserThrowsNotFound(): void
    {
        $admin = $this->createUser('dev.admin5', 'Тестовый администратор 5');
        $roleId = (int) $this->scalar("SELECT id FROM {{%roles}} WHERE code = 'employee'");
        $repository = new UserAdministrationRepository($this->db());

        $this->expectException(UserAdministrationTargetNotFound::class);
        $repository->assignRole(999999, $roleId, $admin);
    }

    public function testRevokeRoleRemovesRoleAndWritesAudit(): void
    {
        $admin = $this->createUser('dev.admin6', 'Тестовый администратор 6');
        $target = $this->createUser('dev.target3', 'Целевой пользователь 3');
        $this->grantRole($target, 'security_officer');
        $roleId = (int) $this->scalar("SELECT id FROM {{%roles}} WHERE code = 'security_officer'");
        $repository = new UserAdministrationRepository($this->db());

        $roles = $repository->revokeRole($target, $roleId, $admin);

        self::assertSame([], $roles);
        $auditCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE event_type = 'user.role_revoked' AND entity_id = :id",
            [':id' => $target],
        );
        self::assertSame(1, (int) $auditCount);
    }

    public function testRevokingAnUnassignedRoleIsNoop(): void
    {
        $admin = $this->createUser('dev.admin7', 'Тестовый администратор 7');
        $target = $this->createUser('dev.target4', 'Целевой пользователь 4');
        $roleId = (int) $this->scalar("SELECT id FROM {{%roles}} WHERE code = 'administrator'");
        $repository = new UserAdministrationRepository($this->db());

        $roles = $repository->revokeRole($target, $roleId, $admin);

        self::assertSame([], $roles);
        $auditCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE event_type = 'user.role_revoked' AND entity_id = :id",
            [':id' => $target],
        );
        self::assertSame(0, (int) $auditCount);
    }

    public function testPreProvisionedRoleSurvivesFirstLdapLogin(): void
    {
        // Ровно сценарий, ради которого заведена эта фича: администратор
        // назначает роль руководителю ИЦ до того, как тот впервые залогинится.
        $admin = $this->createUser('dev.admin8', 'Тестовый администратор 8');
        $repository = new UserAdministrationRepository($this->db());
        $placeholder = $repository->createPlaceholder('kashin2', 'Сергей Кашин', $admin);
        $managerRoleId = (int) $this->scalar("SELECT id FROM {{%roles}} WHERE code = 'ic_manager'");
        $repository->assignRole($placeholder['id'], $managerRoleId, $admin);

        $ldap = new FakeLdapClient(new LdapProfile(
            'kashin2',
            'Сергей Иванович Кашин',
            'kashin2@shlz.ru',
            'Испытательный центр',
            'Руководитель ИЦ',
        ));
        $authenticator = new LdapAuthenticator($this->db(), $ldap);

        $result = $authenticator->authenticate('kashin2', 'correct-password');

        self::assertSame($placeholder['id'], $result['id']);
        self::assertSame('Сергей Иванович Кашин', $result['displayName']);
        $roleCodes = $this->db()->createCommand(
            'SELECT r.code FROM {{%user_roles}} ur JOIN {{%roles}} r ON r.id = ur.role_id WHERE ur.user_id = :id',
            [':id' => $placeholder['id']],
        )->queryColumn();
        sort($roleCodes);
        self::assertSame(['employee', 'ic_manager'], $roleCodes);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Domain\Identity\DuplicateAdLogin;
use App\Domain\Identity\UserAdministrationTargetNotFound;
use App\Domain\Request\Role;
use App\Infrastructure\Clock;
use yii\db\Connection;
use yii\db\IntegrityException;

final class UserAdministrationRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listUsers(): array
    {
        $users = $this->db->createCommand(
            'SELECT id, ad_login AS adLogin, display_name AS displayName, email, '
            . 'department, position, is_active AS isActive FROM {{%users}} ORDER BY display_name',
        )->queryAll();

        $roleRows = $this->db->createCommand(
            'SELECT ur.user_id AS userId, r.id, r.code, r.name FROM {{%user_roles}} ur '
            . 'JOIN {{%roles}} r ON r.id = ur.role_id ORDER BY r.id',
        )->queryAll();
        $rolesByUser = [];
        foreach ($roleRows as $row) {
            $rolesByUser[(int) $row['userId']][] = [
                'id' => (int) $row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
            ];
        }

        return array_map(static function (array $user) use ($rolesByUser): array {
            $userId = (int) $user['id'];
            return [
                'id' => $userId,
                'adLogin' => $user['adLogin'],
                'displayName' => $user['displayName'],
                'email' => $user['email'],
                'department' => $user['department'],
                'position' => $user['position'],
                'isActive' => (bool) $user['isActive'],
                'roles' => $rolesByUser[$userId] ?? [],
            ];
        }, $users);
    }

    /** @return list<array<string, mixed>> */
    public function listRoles(): array
    {
        return $this->db->createCommand(
            'SELECT id, code, name FROM {{%roles}} ORDER BY id',
        )->queryAll();
    }

    /**
     * Заводит локальный профиль заранее, до первого входа сотрудника через
     * LDAP — тем самым можно назначить роль (например, руководителю ИЦ),
     * не дожидаясь, пока он сам залогинится. При первом реальном входе
     * find-or-create в LdapAuthenticator найдёт эту же строку по ad_login
     * и просто обновит display_name/email/department/position из AD, не
     * тронув is_active и уже назначенные роли (AUTH-004).
     *
     * @return array<string, mixed>
     */
    public function createPlaceholder(string $adLogin, string $displayName, int $actorId): array
    {
        $now = Clock::now();
        $transaction = $this->db->beginTransaction();
        try {
            try {
                $this->db->createCommand()->insert('{{%users}}', [
                    'ad_login' => $adLogin,
                    'display_name' => $displayName,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->execute();
            } catch (IntegrityException $error) {
                throw new DuplicateAdLogin($adLogin);
            }
            $userId = (int) $this->db->getLastInsertID();

            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'user.pre_provisioned',
                'entity_type' => 'user',
                'entity_id' => $userId,
                'actor_id' => $actorId,
                'rule_id' => 'AUTH-007',
                'payload_json' => ['ad_login' => $adLogin, 'display_name' => $displayName],
                'created_at' => $now,
            ])->execute();

            // AUTH-002: обычный первый вход даёт базовую роль «Сотрудник» —
            // pre-provisioning должен приводить к тому же исходному состоянию,
            // иначе заранее заведённый профиль будет отличаться от того, что
            // получил бы тот же человек, просто залогинившись сам.
            $employeeRoleId = $this->db->createCommand(
                "SELECT id FROM {{%roles}} WHERE code = 'employee'",
            )->queryScalar();
            if ($employeeRoleId !== false) {
                $this->db->createCommand()->insert('{{%user_roles}}', [
                    'user_id' => $userId,
                    'role_id' => (int) $employeeRoleId,
                    'assigned_by' => $actorId,
                    'created_at' => $now,
                ])->execute();
            }

            $result = [
                'id' => $userId,
                'adLogin' => $adLogin,
                'displayName' => $displayName,
                'email' => null,
                'department' => null,
                'position' => null,
                'isActive' => true,
                'roles' => $this->rolesOf($userId),
            ];
            $transaction->commit();
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function assignRole(int $userId, int $roleId, int $actorId): array
    {
        $this->assertUserExists($userId);
        $role = $this->db->createCommand(
            'SELECT id FROM {{%roles}} WHERE id = :id',
            [':id' => $roleId],
        )->queryScalar();
        if ($role === false) {
            throw new UserAdministrationTargetNotFound('Role not found');
        }

        $alreadyAssigned = $this->db->createCommand(
            'SELECT 1 FROM {{%user_roles}} WHERE user_id = :user_id AND role_id = :role_id',
            [':user_id' => $userId, ':role_id' => $roleId],
        )->queryScalar() !== false;

        if (!$alreadyAssigned) {
            $now = Clock::now();
            try {
                $this->db->createCommand()->insert('{{%user_roles}}', [
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'assigned_by' => $actorId,
                    'created_at' => $now,
                ])->execute();
            } catch (IntegrityException $error) {
                // Гонка check-then-insert: другой параллельный запрос успел
                // назначить эту же роль между нашей проверкой и вставкой —
                // тот же идемпотентный результат, что и alreadyAssigned,
                // без дублирующей записи аудита.
                return $this->rolesOf($userId);
            }
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'user.role_assigned',
                'entity_type' => 'user',
                'entity_id' => $userId,
                'actor_id' => $actorId,
                'rule_id' => 'AUTH-007',
                'payload_json' => ['role_id' => $roleId],
                'created_at' => $now,
            ])->execute();
        }

        return $this->rolesOf($userId);
    }

    /** @return list<array<string, mixed>> */
    public function revokeRole(int $userId, int $roleId, int $actorId): array
    {
        $this->assertUserExists($userId);
        $deleted = $this->db->createCommand()->delete(
            '{{%user_roles}}',
            ['user_id' => $userId, 'role_id' => $roleId],
        )->execute();

        if ($deleted > 0) {
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'user.role_revoked',
                'entity_type' => 'user',
                'entity_id' => $userId,
                'actor_id' => $actorId,
                'rule_id' => 'AUTH-007',
                'payload_json' => ['role_id' => $roleId],
                'created_at' => Clock::now(),
            ])->execute();
        }

        return $this->rolesOf($userId);
    }

    /** @return list<Role> */
    public function rolesFor(int $userId): array
    {
        $codes = $this->db->createCommand(
            'SELECT r.code FROM {{%roles}} r JOIN {{%user_roles}} ur ON ur.role_id = r.id WHERE ur.user_id = :id',
            [':id' => $userId],
        )->queryColumn();

        return array_values(array_filter(array_map(
            static fn (string $code): ?Role => Role::tryFrom($code),
            $codes,
        )));
    }

    private function assertUserExists(int $userId): void
    {
        $exists = $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id',
            [':id' => $userId],
        )->queryScalar() !== false;
        if (!$exists) {
            throw new UserAdministrationTargetNotFound('User not found');
        }
    }

    /** @return list<array<string, mixed>> */
    private function rolesOf(int $userId): array
    {
        return $this->db->createCommand(
            'SELECT r.id, r.code, r.name FROM {{%user_roles}} ur '
            . 'JOIN {{%roles}} r ON r.id = ur.role_id WHERE ur.user_id = :id ORDER BY r.id',
            [':id' => $userId],
        )->queryAll();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Identity\UserAdministrationRepository;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;
use yii\db\IntegrityException;

final class UserRoleAuditAtomicityTest extends TestCase
{
    public function testAssignRoleRollsBackDomainMutationWhenAuditWriteFails(): void
    {
        $db = $this->connection();
        $marker = bin2hex(random_bytes(6));
        $now = gmdate('Y-m-d H:i:s');
        $ids = [];

        try {
            foreach (['actor', 'target'] as $kind) {
                $db->createCommand()->insert('{{%users}}', [
                    'ad_login' => "role-atomicity-{$kind}-{$marker}",
                    'display_name' => "Role atomicity {$kind}",
                    'email' => "role-atomicity-{$kind}-{$marker}@example.invalid",
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->execute();
                $ids[$kind] = (int) $db->getLastInsertID();
            }
            $roleId = $this->roleId($db, 'expert');
            try {
                (new UserAdministrationRepository($db))->assignRole($ids['target'], $roleId, $ids['actor']);
                self::fail('Expected the controlled audit write to fail.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('controlled audit failure', $error->getMessage());
            }

            self::assertNull($db->getTransaction());
            $roleCount = $db->createCommand(
                'SELECT COUNT(*) FROM {{%user_roles}} WHERE user_id = :user AND role_id = :role',
                [':user' => $ids['target'], ':role' => $roleId],
            )->queryScalar();
            self::assertSame(0, (int) $roleCount, 'Role assignment and its audit event must be atomic.');
            self::assertSame(0, $this->auditCount($db, $ids['target'], $roleId));
        } finally {
            if ($ids !== []) {
                $db->createCommand()->delete('{{%audit_events}}', ['actor_id' => array_values($ids)])->execute();
                $db->createCommand()->delete('{{%user_roles}}', ['user_id' => array_values($ids)])->execute();
                $db->createCommand()->delete('{{%users}}', ['id' => array_values($ids)])->execute();
            }
            $db->close();
        }
    }

    public function testAssignRolePropagatesUnrelatedIntegrityFailure(): void
    {
        $db = $this->connection();
        $ids = $this->createUsers($db, ['target']);
        $roleId = $this->roleId($db, 'expert');

        try {
            try {
                (new UserAdministrationRepository($db))->assignRole($ids['target'], $roleId, 999999999);
                self::fail('Expected the foreign-key integrity failure to propagate.');
            } catch (IntegrityException) {
            }

            self::assertNull($db->getTransaction());
            self::assertSame(0, $this->roleCount($db, $ids['target'], $roleId));
            self::assertSame(0, $this->auditCount($db, $ids['target'], $roleId));
        } finally {
            $this->deleteUsers($db, $ids);
            $db->close();
        }
    }

    public function testAssignRoleTreatsCommittedConcurrentDuplicateAsIdempotent(): void
    {
        $db = $this->connection();
        $ids = $this->createUsers($db, ['actor', 'target']);
        $roleId = $this->roleId($db, 'expert');

        try {
            ControlledAuditFailureCommand::$injectConcurrentRoleAssignment = true;
            $roles = (new UserAdministrationRepository($db))->assignRole(
                $ids['target'],
                $roleId,
                $ids['actor'],
            );

            self::assertNull($db->getTransaction());
            self::assertSame(['expert'], array_column($roles, 'code'));
            self::assertSame(1, $this->roleCount($db, $ids['target'], $roleId));
            self::assertSame(1, $this->auditCount($db, $ids['target'], $roleId));
        } finally {
            ControlledAuditFailureCommand::$injectConcurrentRoleAssignment = false;
            $this->deleteUsers($db, $ids);
            $db->close();
        }
    }

    /**
     * @param list<string> $kinds
     * @return array<string, int>
     */
    private function createUsers(Connection $db, array $kinds): array
    {
        $marker = bin2hex(random_bytes(6));
        $now = gmdate('Y-m-d H:i:s');
        $ids = [];
        foreach ($kinds as $kind) {
            $db->createCommand()->insert('{{%users}}', [
                'ad_login' => "role-integrity-{$kind}-{$marker}",
                'display_name' => "Role integrity {$kind}",
                'email' => "role-integrity-{$kind}-{$marker}@example.invalid",
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
            $ids[$kind] = (int) $db->getLastInsertID();
        }
        return $ids;
    }

    /** @param array<string, int> $ids */
    private function deleteUsers(Connection $db, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $db->createCommand()->delete('{{%audit_events}}', ['actor_id' => array_values($ids)])->execute();
        $db->createCommand()->delete('{{%user_roles}}', ['user_id' => array_values($ids)])->execute();
        $db->createCommand()->delete('{{%users}}', ['id' => array_values($ids)])->execute();
    }

    private function roleId(Connection $db, string $code): int
    {
        $roleId = $db->createCommand(
            'SELECT id FROM {{%roles}} WHERE code = :code',
            [':code' => $code],
        )->queryScalar();
        self::assertNotFalse($roleId, "Required role seed '{$code}' is missing.");
        return (int) $roleId;
    }

    private function roleCount(Connection $db, int $userId, int $roleId): int
    {
        return (int) $db->createCommand(
            'SELECT COUNT(*) FROM {{%user_roles}} WHERE user_id = :user AND role_id = :role',
            [':user' => $userId, ':role' => $roleId],
        )->queryScalar();
    }

    private function auditCount(Connection $db, int $userId, int $roleId): int
    {
        return (int) $db->createCommand(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE event_type = 'user.role_assigned' "
            . "AND entity_id = :user AND JSON_VALUE(payload_json, '$.role_id') = :role",
            [':user' => $userId, ':role' => $roleId],
        )->queryScalar();
    }

    private function connection(): Connection
    {
        $connection = new Connection([
            'dsn' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_NAME') ?: 'ic_test',
            ),
            'username' => getenv('DB_USER') ?: 'ic',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'commandClass' => ControlledAuditFailureCommand::class,
        ]);
        $connection->open();
        return $connection;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Identity\UserAdministrationRepository;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

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
            $roleId = (int) $db->createCommand("SELECT id FROM {{%roles}} WHERE code = 'expert'")->queryScalar();
            try {
                (new UserAdministrationRepository($db))->assignRole($ids['target'], $roleId, $ids['actor']);
                self::fail('Expected the controlled audit write to fail.');
            } catch (\Throwable $error) {
                self::assertStringContainsString('controlled audit failure', $error->getMessage());
            }

            $roleCount = $db->createCommand(
                'SELECT COUNT(*) FROM {{%user_roles}} WHERE user_id = :user AND role_id = :role',
                [':user' => $ids['target'], ':role' => $roleId],
            )->queryScalar();
            self::assertSame(0, (int) $roleCount, 'Role assignment and its audit event must be atomic.');
        } finally {
            if ($ids !== []) {
                $db->createCommand()->delete('{{%audit_events}}', ['actor_id' => array_values($ids)])->execute();
                $db->createCommand()->delete('{{%user_roles}}', ['user_id' => array_values($ids)])->execute();
                $db->createCommand()->delete('{{%users}}', ['id' => array_values($ids)])->execute();
            }
            $db->close();
        }
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

<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\Command\SetRequestColorCommand;
use App\Application\Request\CreateRequestInput;
use App\Application\Request\UseCase\SetRequestColor;
use App\Domain\Request\RequestColor;
use App\Infrastructure\Persistence\Request\RequestColorPersistenceAdapter;
use App\Infrastructure\Request\RequestRepository;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class SetRequestColorAuditAtomicityTest extends TestCase
{
    public function testColorMutationRollsBackWhenAuditWriteFails(): void
    {
        $db = $this->connection();
        $testTransaction = $db->beginTransaction();
        $marker = bin2hex(random_bytes(6));
        $now = gmdate('Y-m-d H:i:s');

        try {
            $initiatorId = $this->createUser($db, "color-atomicity-initiator-{$marker}", $now);
            $managerId = $this->createUser($db, "color-atomicity-manager-{$marker}", $now);
            $this->grantRole($db, $managerId, 'ic_manager', $now);
            $input = new CreateRequestInput();
            $input->setAttributes([
                'productName' => "Color atomicity {$marker}",
                'manufacturer' => 'Завод',
                'supplier' => 'Поставщик',
                'sampleQuantity' => 1,
                'testMethod' => 'Методика',
            ]);
            $repository = new RequestRepository($db);
            $request = $repository->create($input, $initiatorId);

            try {
                (new SetRequestColor(new RequestColorPersistenceAdapter($db)))->execute(new SetRequestColorCommand(
                    (int) $request['id'],
                    RequestColor::Red,
                    (int) $request['lock_version'],
                    $managerId,
                ));
                self::fail('Expected the controlled audit write to fail.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('controlled color audit failure', $error->getMessage());
            }

            self::assertSame(['color' => 'white', 'lock_version' => (int) $request['lock_version']], $db->createCommand(
                'SELECT color, lock_version FROM {{%requests}} WHERE id = :id',
                [':id' => $request['id']],
            )->queryOne());
            self::assertSame(0, (int) $db->createCommand(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.color_marked'",
                [':id' => $request['id']],
            )->queryScalar());
        } finally {
            if ($testTransaction->isActive) {
                $testTransaction->rollBack();
            }
            $db->close();
        }
    }

    private function createUser(Connection $db, string $login, string $now): int
    {
        $db->createCommand()->insert('{{%users}}', [
            'ad_login' => $login,
            'display_name' => $login,
            'email' => "{$login}@example.invalid",
            'department' => 'Тестовое подразделение',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $db->getLastInsertID();
    }

    private function grantRole(Connection $db, int $userId, string $role, string $now): void
    {
        $roleId = $db->createCommand('SELECT id FROM {{%roles}} WHERE code = :code', [':code' => $role])->queryScalar();
        $db->createCommand()->insert('{{%user_roles}}', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => $now,
        ])->execute();
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
            'commandClass' => ControlledColorAuditFailureCommand::class,
        ]);
        $connection->open();
        return $connection;
    }
}

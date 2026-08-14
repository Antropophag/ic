<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\Command\ChangeRequestDepartmentCommand;
use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Application\Request\UseCase\ChangeRequestDepartment;
use App\Infrastructure\Persistence\Request\RequestDepartmentPersistenceAdapter;
use App\Infrastructure\Request\RequestRepository;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class ChangeRequestDepartmentAuditAtomicityTest extends TestCase
{
    public function testDepartmentMutationRollsBackWhenAuditWriteFails(): void
    {
        $db = $this->connection();
        $testTransaction = $db->beginTransaction();
        $marker = bin2hex(random_bytes(6));
        $now = gmdate('Y-m-d H:i:s');

        try {
            $initiatorId = $this->createUser($db, "department-atomicity-initiator-{$marker}", $now);
            $administratorId = $this->createUser($db, "department-atomicity-admin-{$marker}", $now);
            $this->grantRole($db, $administratorId, 'administrator', $now);
            $input = new CreateRequestInput();
            $input->setAttributes([
                'productName' => "Department atomicity {$marker}",
                'manufacturer' => 'Завод',
                'supplier' => 'Поставщик',
                'sampleQuantity' => 1,
                'testMethod' => 'Методика',
            ]);
            $request = (new \App\Application\Request\UseCase\CreateRequest(
                new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($db),
            ))->execute($input->toCommand($initiatorId))->toArray();
            $db->createCommand()->update('{{%requests}}', [
                'department_external_id' => 'bitrix:atomicity',
                'department_source' => 'bitrix24',
            ], ['id' => $request['id']])->execute();

            try {
                (new ChangeRequestDepartment(new RequestDepartmentPersistenceAdapter($db)))->execute(new ChangeRequestDepartmentCommand(
                    (int) $request['id'],
                    'Новое подразделение',
                    (int) $request['lock_version'],
                    $administratorId,
                ));
                self::fail('Expected the controlled audit write to fail.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('controlled department audit failure', $error->getMessage());
            }

            self::assertSame([
                'department_name' => 'Тестовое подразделение',
                'department_external_id' => 'bitrix:atomicity',
                'department_source' => 'bitrix24',
                'lock_version' => (int) $request['lock_version'],
            ], $db->createCommand(
                'SELECT department_name, department_external_id, department_source, lock_version '
                . 'FROM {{%requests}} WHERE id = :id',
                [':id' => $request['id']],
            )->queryOne());
            self::assertSame(0, (int) $db->createCommand(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id "
                . "AND event_type = 'request.department_changed'",
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
        $roleId = $db->createCommand(
            'SELECT id FROM {{%roles}} WHERE code = :code',
            [':code' => $role],
        )->queryScalar();
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
            'commandClass' => ControlledDepartmentAuditFailureCommand::class,
        ]);
        $connection->open();
        return $connection;
    }
}

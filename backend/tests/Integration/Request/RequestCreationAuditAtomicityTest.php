<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Http\Request\CreateRequest as CreateRequestInput;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class RequestCreationAuditAtomicityTest extends TestCase
{
    public function testCreationRollsBackWhenAuditWriteFails(): void
    {
        $db = $this->connection();
        $testTransaction = $db->beginTransaction();
        $marker = bin2hex(random_bytes(6));
        $now = gmdate('Y-m-d H:i:s');
        $initiatorId = null;

        try {
            $db->createCommand()->insert('{{%users}}', [
                'ad_login' => "request-audit-atomicity-{$marker}",
                'display_name' => 'Request audit atomicity',
                'email' => "request-audit-atomicity-{$marker}@example.invalid",
                'department' => 'Тестовое подразделение',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
            $initiatorId = (int) $db->getLastInsertID();

            $input = new CreateRequestInput();
            $input->productName = "Request audit atomicity {$marker}";
            $input->manufacturer = 'Тестовый завод';
            $input->supplier = 'Тестовый поставщик';
            $input->sampleQuantity = 1;
            $input->testMethod = 'Интеграционный тест';

            try {
                (new \App\Application\Request\UseCase\CreateRequest(
                    new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($db),
                ))->execute($input->toCommand($initiatorId));
                self::fail('Expected the controlled audit write to fail.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('controlled request creation audit failure', $error->getMessage());
            }

            self::assertTrue($testTransaction->isActive);
            self::assertSame(0, (int) $db->createCommand(
                'SELECT COUNT(*) FROM {{%requests}} WHERE product_name = :product',
                [':product' => $input->productName],
            )->queryScalar(), 'The request and its audit event must be atomic.');
            self::assertSame(0, (int) $db->createCommand(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE actor_id = :actor AND event_type = 'request.created'",
                [':actor' => $initiatorId],
            )->queryScalar());
        } finally {
            if ($testTransaction->isActive) {
                $testTransaction->rollBack();
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
            'commandClass' => ControlledCreationAuditFailureCommand::class,
        ]);
        $connection->open();
        return $connection;
    }
}

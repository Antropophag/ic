<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\Command\AddCommentCommand;
use App\Application\Request\UseCase\AddComment;
use App\Http\Request\CreateRequest;
use App\Infrastructure\Persistence\Request\RequestCommentPersistenceAdapter;
use App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class AddCommentAtomicityTest extends TestCase
{
    public function testCommentRollsBackWhenAuditWriteFails(): void
    {
        $db = $this->connection();
        $outer = $db->beginTransaction();
        $marker = bin2hex(random_bytes(6));
        try {
            $actorId = $this->createUser($db, "comment-atomicity-{$marker}");
            $input = new CreateRequest();
            $input->setAttributes([
                'productName' => "Comment atomicity {$marker}",
                'manufacturer' => 'Завод',
                'supplier' => 'Поставщик',
                'sampleQuantity' => 1,
                'testMethod' => 'Методика',
            ]);
            $request = (new \App\Application\Request\UseCase\CreateRequest(
                new RequestCreationPersistenceAdapter($db),
            ))->execute($input->toCommand($actorId))->toArray();

            try {
                (new AddComment(new RequestCommentPersistenceAdapter($db)))->execute(
                    new AddCommentCommand((int) $request['id'], $actorId, 'Откатить'),
                );
                self::fail('Expected the controlled audit write to fail.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('controlled comment audit failure', $error->getMessage());
            }

            self::assertSame(0, (int) $db->createCommand(
                'SELECT COUNT(*) FROM {{%request_comments}} WHERE request_id = :id',
                [':id' => $request['id']],
            )->queryScalar());
            self::assertSame(0, (int) $db->createCommand(
                "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.commented'",
                [':id' => $request['id']],
            )->queryScalar());
        } finally {
            if ($outer->isActive) {
                $outer->rollBack();
            }
            $db->close();
        }
    }

    private function createUser(Connection $db, string $login): int
    {
        $now = gmdate('Y-m-d H:i:s');
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
            'commandClass' => ControlledCommentAuditFailureCommand::class,
        ]);
        $connection->open();
        return $connection;
    }
}

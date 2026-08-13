<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\Command\AssignExpertCommand;
use App\Application\Request\Command\ExpertAssignmentAction;
use App\Application\Request\UseCase\AssignExpert;
use App\Domain\Request\RequestStatus;
use App\Infrastructure\Persistence\Request\ExpertAssignmentPersistenceAdapter;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class ExpertAssignmentAtomicityTest extends TestCase
{
    public function testMutationHistoryAndOutboxRollBackWhenAuditWriteFails(): void
    {
        $db = $this->connection();
        $outer = $db->beginTransaction();
        $now = gmdate('Y-m-d H:i:s');
        try {
            $current = $this->user($db, 'atomic-current-' . bin2hex(random_bytes(4)), $now);
            $target = $this->user($db, 'atomic-target-' . bin2hex(random_bytes(4)), $now);
            $this->role($db, $current, $now);
            $this->role($db, $target, $now);
            $requestId = $this->request($db, $current, $now);
            $db->createCommand()->insert('{{%request_assignments}}', [
                'request_id' => $requestId,
                'assignment_type' => 'expert',
                'user_id' => $current,
                'assigned_by' => $current,
                'valid_from' => $now,
            ])->execute();

            try {
                (new AssignExpert(new ExpertAssignmentPersistenceAdapter($db)))->execute(
                    new AssignExpertCommand(ExpertAssignmentAction::Reassign, $requestId, $target, 1, $current),
                );
                self::fail('Expected controlled audit failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('controlled expert assignment audit failure', $error->getMessage());
            }

            self::assertSame(1, (int) $db->createCommand(
                'SELECT lock_version FROM {{%requests}} WHERE id = :id',
                [':id' => $requestId],
            )->queryScalar());
            self::assertSame([$current], array_map('intval', $db->createCommand(
                "SELECT user_id FROM {{%request_assignments}} WHERE request_id = :id "
                . "AND assignment_type = 'expert' AND valid_to IS NULL",
                [':id' => $requestId],
            )->queryColumn()));
            self::assertSame(1, (int) $db->createCommand(
                "SELECT COUNT(*) FROM {{%request_assignments}} WHERE request_id = :id AND assignment_type = 'expert'",
                [':id' => $requestId],
            )->queryScalar());
            self::assertSame(0, (int) $db->createCommand(
                "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id",
                [':id' => $requestId],
            )->queryScalar());
        } finally {
            if ($outer->isActive) {
                $outer->rollBack();
            }
            $db->close();
        }
    }

    private function user(Connection $db, string $login, string $now): int
    {
        $db->createCommand()->insert('{{%users}}', [
            'ad_login' => $login,
            'display_name' => $login,
            'email' => "{$login}@example.invalid",
            'department' => 'Тест',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $db->getLastInsertID();
    }

    private function role(Connection $db, int $userId, string $now): void
    {
        $roleId = $db->createCommand("SELECT id FROM {{%roles}} WHERE code = 'expert'")->queryScalar();
        $db->createCommand()->insert('{{%user_roles}}', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => $now,
        ])->execute();
    }

    private function request(Connection $db, int $initiatorId, string $now): int
    {
        $db->createCommand()->insert('{{%requests}}', [
            'number' => random_int(1_000_000_000, 2_000_000_000),
            'status' => RequestStatus::OpinionPreparation->value,
            'product_name' => 'Atomic expert assignment',
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sample_quantity' => 1,
            'test_method' => 'Методика',
            'initiator_id' => $initiatorId,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $db->getLastInsertID();
    }

    private function connection(): Connection
    {
        $db = new Connection([
            'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'ic_test'),
            'username' => getenv('DB_USER') ?: 'ic',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'commandClass' => ControlledExpertAssignmentAuditFailureCommand::class,
        ]);
        $db->open();
        return $db;
    }
}

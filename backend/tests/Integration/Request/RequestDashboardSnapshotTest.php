<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Infrastructure\Request\RequestDashboardQuery;
use PHPUnit\Framework\TestCase;
use Tests\Support\TrackingConnection;
use yii\db\Connection;
use yii\db\Transaction;

final class RequestDashboardSnapshotTest extends TestCase
{
    public function testDashboardOwnsAReadSnapshotWithoutAnOuterTransaction(): void
    {
        $db = $this->connection();
        $db->open();
        try {
            $managerId = (int) $db->createCommand(
                "SELECT user.id FROM {{%users}} user JOIN {{%user_roles}} user_role ON user_role.user_id = user.id "
                . "JOIN {{%roles}} role ON role.id = user_role.role_id WHERE role.code = 'ic_manager' LIMIT 1",
            )->queryScalar();
            self::assertGreaterThan(0, $managerId);
            $beginCount = 0;
            $db->on(Connection::EVENT_BEGIN_TRANSACTION, static function () use (&$beginCount): void {
                $beginCount++;
            });

            $dashboard = (new RequestDashboardQuery($db))->findFor($managerId);

            self::assertSame(1, $beginCount);
            self::assertSame(Transaction::REPEATABLE_READ, $db->lastIsolationLevel);
            self::assertNull($db->getTransaction());
            self::assertArrayHasKey('categories', $dashboard);
            self::assertArrayHasKey('active', $dashboard['operational_summary']);
        } finally {
            $db->close();
        }
    }

    public function testExistingTransactionRemainsCallerOwned(): void
    {
        $db = $this->connection();
        $db->open();
        $managerId = $this->managerId($db);
        $transaction = $db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            (new RequestDashboardQuery($db))->findFor($managerId);

            self::assertTrue($transaction->isActive);
            self::assertSame(1, $transaction->level);
            self::assertSame(Transaction::READ_COMMITTED, $db->lastIsolationLevel);
        } finally {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            $db->close();
        }
    }

    public function testMariaDbReadCommittedAndRepeatableReadHaveDifferentVisibility(): void
    {
        self::assertSame([0, 1], $this->visibilityAcrossConcurrentInsert(Transaction::READ_COMMITTED, 'dashboard.rc'));
        self::assertSame([0, 0], $this->visibilityAcrossConcurrentInsert(Transaction::REPEATABLE_READ, 'dashboard.rr'));
    }

    /** @return array{int, int} */
    private function visibilityAcrossConcurrentInsert(string $isolation, string $login): array
    {
        $reader = $this->connection();
        $writer = $this->connection();
        $reader->open();
        $writer->open();
        $transaction = $reader->beginTransaction($isolation);
        try {
            $before = $this->userCount($reader, $login);
            $writer->createCommand()->insert('{{%users}}', [
                'ad_login' => $login,
                'display_name' => 'Isolation fixture',
                'email' => "{$login}@example.invalid",
                'is_active' => 1,
                'created_at' => '2026-08-17 00:00:00.000000',
                'updated_at' => '2026-08-17 00:00:00.000000',
            ])->execute();
            $after = $this->userCount($reader, $login);
            return [$before, $after];
        } finally {
            $transaction->rollBack();
            $writer->createCommand()->delete('{{%users}}', ['ad_login' => $login])->execute();
            $reader->close();
            $writer->close();
        }
    }

    private function userCount(Connection $db, string $login): int
    {
        return (int) $db->createCommand(
            'SELECT COUNT(*) FROM {{%users}} WHERE ad_login = :login',
            [':login' => $login],
        )->queryScalar();
    }

    private function managerId(Connection $db): int
    {
        $managerId = (int) $db->createCommand(
            "SELECT user.id FROM {{%users}} user JOIN {{%user_roles}} user_role ON user_role.user_id = user.id "
            . "JOIN {{%roles}} role ON role.id = user_role.role_id WHERE role.code = 'ic_manager' LIMIT 1",
        )->queryScalar();
        self::assertGreaterThan(0, $managerId);
        return $managerId;
    }

    private function connection(): TrackingConnection
    {
        return new TrackingConnection([
            'dsn' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_NAME') ?: 'ic_test',
            ),
            'username' => getenv('DB_USER') ?: 'ic',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ]);
    }
}

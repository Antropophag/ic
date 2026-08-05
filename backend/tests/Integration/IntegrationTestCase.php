<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Clock;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;
use yii\db\Transaction;

/**
 * Базовый класс интеграционных тестов Infrastructure-репозиториев.
 *
 * Требует реальную MariaDB с уже применёнными миграциями (см.
 * scripts/backend-integration.sh и phpunit.integration.xml). Каждый тест
 * выполняется в собственной транзакции, откатываемой в tearDown — методы
 * репозиториев используют savepoints для своих внутренних транзакций
 * (Connection::$enableSavepoint включён в Yii по умолчанию), поэтому откат
 * внешней транзакции безопасно отменяет все изменения теста целиком.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?Connection $connection = null;
    private ?Transaction $transaction = null;

    protected function db(): Connection
    {
        if (self::$connection === null) {
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
            ]);
            $connection->open();
            self::$connection = $connection;
        }
        return self::$connection;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->transaction = $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->transaction?->rollBack();
        $this->transaction = null;
        parent::tearDown();
    }

    protected function createUser(
        string $adLogin,
        string $displayName,
        ?string $email = null,
        bool $isActive = true,
        ?string $department = 'Тестовое подразделение',
    ): int {
        $now = Clock::now();
        $this->db()->createCommand()->insert('{{%users}}', [
            'ad_login' => $adLogin,
            'display_name' => $displayName,
            'email' => $email ?? ($adLogin . '@example.invalid'),
            'department' => $department,
            'is_active' => $isActive,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db()->getLastInsertID();
    }

    protected function grantRole(int $userId, string $roleCode): void
    {
        $roleId = $this->db()->createCommand(
            'SELECT id FROM {{%roles}} WHERE code = :code',
            [':code' => $roleCode],
        )->queryScalar();
        if ($roleId === false) {
            throw new \RuntimeException("Unknown role code: {$roleCode}");
        }
        $this->db()->createCommand()->insert('{{%user_roles}}', [
            'user_id' => $userId,
            'role_id' => (int) $roleId,
            'created_at' => Clock::now(),
        ])->execute();
    }

    /** @param array<string, mixed> $params */
    protected function scalar(string $sql, array $params = []): mixed
    {
        return $this->db()->createCommand($sql, $params)->queryScalar();
    }
}

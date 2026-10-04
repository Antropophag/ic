<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\ArrayCache;
use yii\console\Application;
use yii\db\Connection;
use yii\db\Migration;

final class NotificationOutboxMigrationResumabilityTest extends TestCase
{
    public function testMissingSchemaStageAndImmediateRerunReachSameFinalSchema(): void
    {
        $previousApplication = Yii::$app;
        $application = new Application([
            'id' => 'notification-migration-test',
            'basePath' => dirname(__DIR__, 3),
            'components' => ['cache' => ['class' => ArrayCache::class]],
        ]);
        $db = new Connection([
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
        $db->open();
        $db->createCommand('ALTER TABLE `notification_outbox` DROP COLUMN `payload_json`')->execute();
        $migration = $this->migration($db);

        $migration->safeUp();
        $first = $db->schema->getTableSchema('{{%notification_outbox}}', true);
        self::assertNotNull($first);
        self::assertArrayHasKey('payload_json', $first->columns);
        self::assertFalse($first->columns['payload_json']->allowNull);

        $migration->safeUp();
        $second = $db->schema->getTableSchema('{{%notification_outbox}}', true);
        self::assertNotNull($second);
        self::assertArrayHasKey('payload_json', $second->columns);
        self::assertFalse($second->columns['payload_json']->allowNull);
        $db->close();
        $application->getErrorHandler()->unregister();
        Yii::$app = $previousApplication;
    }

    private function migration(Connection $db): Migration
    {
        $path = dirname(__DIR__, 3) . '/migrations/m260813_000001_secure_notification_download_links.php';
        require_once $path;
        $class = pathinfo($path, PATHINFO_FILENAME);
        $migration = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(Migration::class, $migration);
        $migration->db = $db;
        return $migration;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yii;
use yii\caching\ArrayCache;
use yii\console\Application;
use yii\db\Connection;
use yii\db\Migration;

final class CurrentAssignmentUniquenessMigrationTest extends TestCase
{
    private mixed $previousApplication;
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Yii::$app;
        $this->application = new Application([
            'id' => 'current-assignment-migration-test',
            'basePath' => dirname(__DIR__, 3),
            'components' => ['cache' => ['class' => ArrayCache::class]],
        ]);
    }

    protected function tearDown(): void
    {
        $this->application->getErrorHandler()->unregister();
        Yii::$app = $this->previousApplication;
        parent::tearDown();
    }

    public function testMigrationRejectsExistingDuplicateCurrentAssignmentsBeforeChangingSchema(): void
    {
        $db = $this->connection();
        try {
            $this->createAssignmentTable($db);
            $db->createCommand()->batchInsert(
                '{{%request_assignments}}',
                ['request_id', 'assignment_type', 'valid_to'],
                [[17, 'executor', null], [17, 'executor', null]],
            )->execute();

            try {
                $this->migration($db)->safeUp();
                self::fail('Migration accepted duplicate current assignments.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('request 17 has 2 current executor assignments', $error->getMessage());
            }

            $schema = $db->schema->getTableSchema('{{%request_assignments}}', true);
            self::assertNotNull($schema);
            self::assertArrayNotHasKey('current_assignment', $schema->columns);
            self::assertArrayNotHasKey('uq_assignment_current', $db->schema->findUniqueIndexes($schema));
        } finally {
            $this->dropAssignmentTable($db);
            $db->close();
        }
    }

    public function testDownRemovesGeneratedColumnAndUniqueIndex(): void
    {
        $db = $this->connection();
        try {
            $this->createAssignmentTable($db);
            $migration = $this->migration($db);
            $migration->safeUp();
            $migration->safeDown();

            $schema = $db->schema->getTableSchema('{{%request_assignments}}', true);
            self::assertNotNull($schema);
            self::assertArrayNotHasKey('current_assignment', $schema->columns);
            self::assertArrayNotHasKey('uq_assignment_current', $db->schema->findUniqueIndexes($schema));
            $db->createCommand()->batchInsert(
                '{{%request_assignments}}',
                ['request_id', 'assignment_type', 'valid_to'],
                [[23, 'expert', null], [23, 'expert', null]],
            )->execute();
        } finally {
            $this->dropAssignmentTable($db);
            $db->close();
        }
    }

    private function connection(): Connection
    {
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
            'tablePrefix' => 'f306_' . bin2hex(random_bytes(5)) . '_',
        ]);
        $db->open();

        return $db;
    }

    private function createAssignmentTable(Connection $db): void
    {
        $db->createCommand()->createTable('{{%request_assignments}}', [
            'id' => 'bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'request_id' => 'bigint unsigned NOT NULL',
            'assignment_type' => 'varchar(16) NOT NULL',
            'valid_to' => 'datetime(6) NULL',
        ])->execute();
    }

    private function dropAssignmentTable(Connection $db): void
    {
        $db->createCommand('DROP TABLE IF EXISTS ' . $db->quoteTableName($db->tablePrefix . 'request_assignments'))->execute();
    }

    private function migration(Connection $db): Migration
    {
        $path = dirname(__DIR__, 3) . '/migrations/m260814_000001_enforce_current_assignment_uniqueness.php';
        require_once $path;
        $class = pathinfo($path, PATHINFO_FILENAME);
        $migration = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(Migration::class, $migration);
        $migration->db = $db;

        return $migration;
    }
}

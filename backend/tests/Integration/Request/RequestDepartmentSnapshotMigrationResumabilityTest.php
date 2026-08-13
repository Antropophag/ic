<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\ArrayCache;
use yii\console\Application;
use yii\db\Connection;
use yii\db\Migration;

final class RequestDepartmentSnapshotMigrationResumabilityTest extends TestCase
{
    public function testCommittedDdlAndPartialBackfillResumeToContinuousFinalState(): void
    {
        $previousApplication = Yii::$app;
        $application = new Application([
            'id' => 'request-department-snapshot-migration-test',
            'basePath' => dirname(__DIR__, 3),
            'components' => ['cache' => ['class' => ArrayCache::class]],
        ]);
        $continuous = $this->connection('continuous');
        $interrupted = $this->connection('interrupted');

        try {
            $this->createPreMigrationState($continuous);
            $this->createPreMigrationState($interrupted);

            $continuousMigration = $this->migration($continuous);
            $continuousMigration->safeUp();

            $this->commitSnapshotDdl($interrupted);
            $interrupted->createCommand()->update('{{%requests}}', [
                'department_name' => 'Исходное подразделение',
                'department_external_id' => null,
                'department_source' => 'migration_current_profile',
            ], ['id' => 1])->execute();
            $interrupted->createCommand()->update('{{%users}}', [
                'department' => 'Изменённое после прерывания подразделение',
            ], ['id' => 1])->execute();

            $interruptedMigration = $this->migration($interrupted);
            $interruptedMigration->safeUp();

            self::assertSame('Исходное подразделение', $interrupted->createCommand(
                'SELECT department_name FROM {{%requests}} WHERE id = 1',
            )->queryScalar(), 'A completed snapshot must not be rebuilt from a changed user profile.');
            self::assertSame([
                'department_name' => 'Подразделение для backfill',
                'department_external_id' => null,
                'department_source' => 'migration_current_profile',
            ], $interrupted->createCommand(
                'SELECT department_name, department_external_id, department_source FROM {{%requests}} WHERE id = 2',
            )->queryOne(), 'A pristine row must be backfilled during recovery.');
            self::assertSame([
                'department_name' => null,
                'department_external_id' => null,
                'department_source' => 'unknown',
            ], $interrupted->createCommand(
                'SELECT department_name, department_external_id, department_source FROM {{%requests}} WHERE id = 3',
            )->queryOne(), 'A missing source department must retain the unknown snapshot state.');
            self::assertSame($this->schema($continuous), $this->schema($interrupted));
            self::assertSame($this->snapshots($continuous), $this->snapshots($interrupted));

            $schemaAfterRecovery = $this->schema($interrupted);
            $snapshotsAfterRecovery = $this->snapshots($interrupted);

            $interruptedMigration->safeUp();

            self::assertSame($schemaAfterRecovery, $this->schema($interrupted));
            self::assertSame($snapshotsAfterRecovery, $this->snapshots($interrupted));
        } finally {
            $this->dropTables($continuous);
            $this->dropTables($interrupted);
            $continuous->close();
            $interrupted->close();
            $application->getErrorHandler()->unregister();
            Yii::$app = $previousApplication;
        }
    }

    private function connection(string $label): Connection
    {
        $prefix = 'f133_' . $label . '_' . bin2hex(random_bytes(5)) . '_';
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
            'tablePrefix' => $prefix,
        ]);
        $connection->open();

        return $connection;
    }

    private function createPreMigrationState(Connection $db): void
    {
        $db->createCommand()->createTable('{{%users}}', [
            'id' => 'int NOT NULL PRIMARY KEY',
            'department' => 'varchar(255) NULL',
        ])->execute();
        $db->createCommand()->createTable('{{%requests}}', [
            'id' => 'int NOT NULL PRIMARY KEY',
            'initiator_id' => 'int NOT NULL',
        ])->execute();
        $db->createCommand()->batchInsert('{{%users}}', ['id', 'department'], [
            [1, 'Исходное подразделение'],
            [2, 'Подразделение для backfill'],
            [3, null],
        ])->execute();
        $db->createCommand()->batchInsert('{{%requests}}', ['id', 'initiator_id'], [
            [1, 1],
            [2, 2],
            [3, 3],
        ])->execute();
    }

    private function commitSnapshotDdl(Connection $db): void
    {
        $db->createCommand()->addColumn('{{%requests}}', 'department_name', 'varchar(255) NULL AFTER initiator_id')->execute();
        $db->createCommand()->addColumn('{{%requests}}', 'department_external_id', 'varchar(128) NULL AFTER department_name')->execute();
        $db->createCommand()->addColumn('{{%requests}}', 'department_source', "varchar(32) NOT NULL DEFAULT 'unknown' AFTER department_external_id")->execute();
    }

    private function migration(Connection $db): Migration
    {
        $path = dirname(__DIR__, 3) . '/migrations/m260805_000002_add_request_department_snapshot.php';
        require_once $path;
        $class = pathinfo($path, PATHINFO_FILENAME);
        $migration = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(Migration::class, $migration);
        $migration->db = $db;

        return $migration;
    }

    /** @return array<string, array{type: string, size: int|null, allowNull: bool, defaultValue: mixed}> */
    private function schema(Connection $db): array
    {
        $table = $db->schema->getTableSchema('{{%requests}}', true);
        self::assertNotNull($table);
        $schema = [];
        foreach (['department_name', 'department_external_id', 'department_source'] as $name) {
            self::assertArrayHasKey($name, $table->columns);
            $column = $table->columns[$name];
            $schema[$name] = [
                'type' => $column->type,
                'size' => $column->size,
                'allowNull' => $column->allowNull,
                'defaultValue' => $column->defaultValue,
            ];
        }

        return $schema;
    }

    /** @return list<array<string, mixed>> */
    private function snapshots(Connection $db): array
    {
        return $db->createCommand(
            'SELECT id, department_name, department_external_id, department_source FROM {{%requests}} ORDER BY id',
        )->queryAll();
    }

    private function dropTables(Connection $db): void
    {
        foreach (['requests', 'users'] as $table) {
            $name = $db->quoteTableName($db->tablePrefix . $table);
            $db->createCommand('DROP TABLE IF EXISTS ' . $name)->execute();
        }
    }
}

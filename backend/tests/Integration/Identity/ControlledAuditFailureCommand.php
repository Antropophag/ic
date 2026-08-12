<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use yii\db\Command;
use yii\db\Connection;

final class ControlledAuditFailureCommand extends Command
{
    public static bool $injectConcurrentRoleAssignment = false;

    /** @param array<string, mixed>|\yii\db\Query $columns */
    public function insert($table, $columns)
    {
        if (
            $table === '{{%user_roles}}'
            && is_array($columns)
            && self::$injectConcurrentRoleAssignment
        ) {
            self::$injectConcurrentRoleAssignment = false;
            $connection = self::independentConnection();
            $transaction = $connection->beginTransaction();
            try {
                $connection->createCommand()->insert('{{%user_roles}}', $columns)->execute();
                $connection->createCommand()->insert('{{%audit_events}}', [
                    'event_type' => 'user.role_assigned',
                    'entity_type' => 'user',
                    'entity_id' => $columns['user_id'],
                    'actor_id' => $columns['assigned_by'],
                    'rule_id' => 'AUTH-007',
                    'payload_json' => ['role_id' => $columns['role_id']],
                    'created_at' => $columns['created_at'],
                ])->execute();
                $transaction->commit();
            } catch (\Throwable $exception) {
                if ($transaction->isActive) {
                    $transaction->rollBack();
                }
                throw $exception;
            } finally {
                $connection->close();
            }
        }
        if (
            $table === '{{%audit_events}}'
            && is_array($columns)
            && ($columns['event_type'] ?? null) === 'user.role_assigned'
        ) {
            throw new \RuntimeException('controlled audit failure');
        }
        return parent::insert($table, $columns);
    }

    private static function independentConnection(): Connection
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
            'commandClass' => Command::class,
        ]);
        $connection->open();
        return $connection;
    }
}

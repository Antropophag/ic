<?php

declare(strict_types=1);

namespace Tests\Integration\Document;

use yii\db\Command;

final class ControlledReportLifecycleFailureCommand extends Command
{
    public static ?string $failure = null;

    /** @param array<string, mixed>|\yii\db\Query $columns */
    public function insert($table, $columns)
    {
        if (
            self::$failure === 'audit'
            && $table === '{{%audit_events}}'
            && is_array($columns)
            && ($columns['event_type'] ?? null) === 'request.report_uploaded'
        ) {
            throw new \RuntimeException('controlled report upload audit failure');
        }
        if (
            self::$failure === 'outbox'
            && $table === '{{%notification_outbox}}'
            && is_array($columns)
            && ($columns['event_type'] ?? null) === 'request.report_uploaded'
        ) {
            throw new \RuntimeException('controlled report upload outbox failure');
        }
        return parent::insert($table, $columns);
    }
}

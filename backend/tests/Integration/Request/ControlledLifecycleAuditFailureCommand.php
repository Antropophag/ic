<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use yii\db\Command;

final class ControlledLifecycleAuditFailureCommand extends Command
{
    /** @param array<string, mixed>|\yii\db\Query $columns */
    public function insert($table, $columns)
    {
        if (
            $table === '{{%audit_events}}'
            && is_array($columns)
            && ($columns['event_type'] ?? null) === 'request.started'
        ) {
            throw new \RuntimeException('controlled lifecycle audit failure');
        }
        return parent::insert($table, $columns);
    }
}

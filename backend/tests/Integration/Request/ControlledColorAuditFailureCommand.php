<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use yii\db\Command;

final class ControlledColorAuditFailureCommand extends Command
{
    /** @param array<string, mixed>|\yii\db\Query $columns */
    public function insert($table, $columns)
    {
        if (
            $table === '{{%audit_events}}'
            && is_array($columns)
            && ($columns['event_type'] ?? null) === 'request.color_marked'
        ) {
            throw new \RuntimeException('controlled color audit failure');
        }
        return parent::insert($table, $columns);
    }
}

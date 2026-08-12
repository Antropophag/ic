<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use yii\db\Command;

final class ControlledAuditFailureCommand extends Command
{
    /** @param array<string, mixed>|\yii\db\Query $columns */
    public function insert($table, $columns)
    {
        if (
            $table === '{{%audit_events}}'
            && is_array($columns)
            && ($columns['event_type'] ?? null) === 'user.role_assigned'
        ) {
            throw new \RuntimeException('controlled audit failure');
        }
        return parent::insert($table, $columns);
    }
}

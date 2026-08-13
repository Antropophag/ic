<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use yii\db\Command;

final class ControlledExpertAssignmentAuditFailureCommand extends Command
{
    /** @param array<string, mixed>|\yii\db\Query $columns */
    public function insert($table, $columns)
    {
        if (
            $table === '{{%audit_events}}'
            && is_array($columns)
            && ($columns['event_type'] ?? null) === 'request.expert_reassigned'
        ) {
            throw new \RuntimeException('controlled expert assignment audit failure');
        }
        return parent::insert($table, $columns);
    }
}

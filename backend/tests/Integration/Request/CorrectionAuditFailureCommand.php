<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use yii\db\Command;

final class CorrectionAuditFailureCommand extends Command
{
    /** @param array<string, mixed>|\yii\db\Query $columns */
    public function insert($table, $columns)
    {
        if ($table === '{{%audit_events}}' && is_array($columns) && ($columns['event_type'] ?? null) === 'request.security_decision_corrected') {
            throw new \RuntimeException('controlled correction audit failure');
        }
        return parent::insert($table, $columns);
    }
}

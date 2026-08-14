<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use yii\db\Command;

final class ControlledPublishOpinionAuditFailureCommand extends Command
{
    /** @param array<string, mixed>|\yii\db\Query $columns */
    public function insert($table, $columns)
    {
        if (
            $table === '{{%audit_events}}'
            && is_array($columns)
            && ($columns['event_type'] ?? null) === 'request.opinion_published'
        ) {
            throw new \RuntimeException('controlled publish opinion audit failure');
        }
        return parent::insert($table, $columns);
    }
}

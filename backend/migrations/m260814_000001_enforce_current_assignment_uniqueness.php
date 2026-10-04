<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260814_000001_enforce_current_assignment_uniqueness extends Migration
{
    public function safeUp(): void
    {
        $duplicate = $this->db->createCommand(
            'SELECT request_id, assignment_type, COUNT(*) AS assignment_count '
            . 'FROM {{%request_assignments}} WHERE valid_to IS NULL '
            . 'GROUP BY request_id, assignment_type HAVING COUNT(*) > 1 LIMIT 1',
        )->queryOne();
        if ($duplicate !== false) {
            throw new RuntimeException(
                'Cannot apply ' . static::class . ': request '
                . $duplicate['request_id'] . ' has ' . $duplicate['assignment_count']
                . ' current ' . $duplicate['assignment_type'] . ' assignments.',
            );
        }

        $this->execute(
            'ALTER TABLE {{%request_assignments}} '
            . 'ADD COLUMN current_assignment TINYINT '
            . 'GENERATED ALWAYS AS (IF(valid_to IS NULL, 1, NULL)) VIRTUAL, '
            . 'ADD UNIQUE INDEX uq_assignment_current '
            . '(request_id, assignment_type, current_assignment)',
        );
    }

    public function safeDown(): void
    {
        $this->execute(
            'ALTER TABLE {{%request_assignments}} '
            . 'DROP INDEX uq_assignment_current, '
            . 'DROP COLUMN current_assignment',
        );
    }
}

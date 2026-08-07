<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260807_000001_add_request_event_recency_indexes extends Migration
{
    public function safeUp(): void
    {
        $this->createIndex(
            'idx_request_comments_event_recency',
            '{{%request_comments}}',
            ['created_at', 'id'],
        );
        $this->createIndex(
            'idx_request_transitions_event_recency',
            '{{%request_transitions}}',
            ['created_at', 'id'],
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_request_transitions_event_recency', '{{%request_transitions}}');
        $this->dropIndex('idx_request_comments_event_recency', '{{%request_comments}}');
    }
}

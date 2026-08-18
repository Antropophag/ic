<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260817_000003_add_ai_conversation_task_type extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%ai_conversations}}',
            'task_type',
            $this->string(16)->notNull()->defaultValue('analysis')->after('id'),
        );
        $this->createIndex(
            'idx_ai_conversation_task',
            '{{%ai_conversations}}',
            ['request_id', 'actor_id', 'task_type', 'created_at'],
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_ai_conversation_task', '{{%ai_conversations}}');
        $this->dropColumn('{{%ai_conversations}}', 'task_type');
    }
}

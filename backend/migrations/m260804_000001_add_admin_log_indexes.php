<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260804_000001_add_admin_log_indexes extends Migration
{
    public function safeUp(): void
    {
        $this->createIndex('idx_audit_created_id', '{{%audit_events}}', ['created_at', 'id']);
        $this->createIndex('idx_audit_actor_created_id', '{{%audit_events}}', ['actor_id', 'created_at', 'id']);
        $this->createIndex('idx_notification_created_id', '{{%notification_outbox}}', ['created_at', 'id']);
        $this->createIndex('idx_notification_request_created_id', '{{%notification_outbox}}', ['request_id', 'created_at', 'id']);
        $this->createIndex('idx_notification_status_created_id', '{{%notification_outbox}}', ['status', 'created_at', 'id']);
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_notification_status_created_id', '{{%notification_outbox}}');
        $this->dropIndex('idx_notification_request_created_id', '{{%notification_outbox}}');
        $this->dropIndex('idx_notification_created_id', '{{%notification_outbox}}');
        $this->dropIndex('idx_audit_actor_created_id', '{{%audit_events}}');
        $this->dropIndex('idx_audit_created_id', '{{%audit_events}}');
    }
}

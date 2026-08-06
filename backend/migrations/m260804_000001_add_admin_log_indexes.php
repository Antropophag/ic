<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260804_000001_add_admin_log_indexes extends Migration
{
    public function safeUp(): void
    {
        $this->createIndex('idx_audit_created_id', '{{%audit_events}}', ['created_at', 'id']);
        $this->createIndex('idx_audit_actor_created_id', '{{%audit_events}}', ['actor_id', 'created_at', 'id']);
        $this->createIndex('idx_audit_entity_created_id', '{{%audit_events}}', ['entity_type', 'entity_id', 'created_at', 'id']);
        $this->dropIndex('idx_audit_entity', '{{%audit_events}}');
        $this->createIndex('idx_notification_created_id', '{{%notification_outbox}}', ['created_at', 'id']);
        $this->createIndex('idx_notification_request_created_id', '{{%notification_outbox}}', ['request_id', 'created_at', 'id']);
        $this->createIndex('idx_notification_status_created_id', '{{%notification_outbox}}', ['status', 'created_at', 'id']);
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_notification_status_created_id', '{{%notification_outbox}}');
        // InnoDB may replace the implicit FK-supporting index with the new composite one.
        // Restore its original index before removing that composite; the FK itself is untouched.
        $this->execute('CREATE INDEX IF NOT EXISTS `fk_notification_request` ON {{%notification_outbox}} (`request_id`)');
        $this->dropIndex('idx_notification_request_created_id', '{{%notification_outbox}}');
        $this->dropIndex('idx_notification_created_id', '{{%notification_outbox}}');
        $this->createIndex('idx_audit_entity', '{{%audit_events}}', ['entity_type', 'entity_id', 'created_at']);
        $this->dropIndex('idx_audit_entity_created_id', '{{%audit_events}}');
        $this->execute('CREATE INDEX IF NOT EXISTS `fk_audit_actor` ON {{%audit_events}} (`actor_id`)');
        $this->dropIndex('idx_audit_actor_created_id', '{{%audit_events}}');
        $this->dropIndex('idx_audit_created_id', '{{%audit_events}}');
    }
}

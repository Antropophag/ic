<?php

declare(strict_types=1);

use yii\db\Migration;

final class m261125_000001_add_optional_security_review extends Migration
{
    public function safeUp(): void
    {
        // Existing protocols keep their security step, status and original decision history.
        $this->addColumn('{{%requests}}', 'security_review_required', $this->boolean()->notNull()->defaultValue(true));
    }

    public function safeDown(): void
    {
        $used = $this->db->createCommand(
            "SELECT 1 FROM {{%requests}} WHERE route = 'protocol' AND security_review_required = 0 LIMIT 1",
        )->queryScalar();
        $changed = $this->db->createCommand(
            "SELECT 1 FROM {{%audit_events}} WHERE event_type = 'request.route_selected' "
            . "AND JSON_EXTRACT(payload_json, '$.security_review_required') IS NOT NULL LIMIT 1",
        )->queryScalar();
        if ($used !== false || $changed !== false) {
            throw new RuntimeException('Route settings are in use; restore the pre-migration backup to preserve workflow history.');
        }
        $this->dropColumn('{{%requests}}', 'security_review_required');
    }
}

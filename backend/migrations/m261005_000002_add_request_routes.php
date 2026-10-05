<?php

declare(strict_types=1);

use yii\db\Migration;

final class m261005_000002_add_request_routes extends Migration
{
    public function safeUp(): void
    {
        // Existing requests retain their full route and status; no synthetic actor is attributed.
        $this->addColumn('{{%requests}}', 'route', $this->string(16)->defaultValue('protocol'));
        $this->addColumn('{{%requests}}', 'route_selected_by', $this->bigInteger()->unsigned());
        $this->addColumn('{{%requests}}', 'route_selected_at', $this->dateTime(6));
        $this->addForeignKey('fk_request_route_actor', '{{%requests}}', 'route_selected_by', '{{%users}}', 'id', 'RESTRICT');
    }

    public function safeDown(): void
    {
        $used = $this->db->createCommand(
            "SELECT 1 FROM {{%requests}} WHERE route IS NULL OR route <> 'protocol' OR route_selected_by IS NOT NULL LIMIT 1",
        )->queryScalar();
        $declined = $this->db->createCommand("SELECT 1 FROM {{%security_checks}} WHERE decision = 'decline' LIMIT 1")->queryScalar();
        if ($used !== false || $declined !== false) {
            throw new RuntimeException('Routes or new security decisions are in use; restore the pre-migration backup to roll back without losing workflow data.');
        }
        $this->dropForeignKey('fk_request_route_actor', '{{%requests}}');
        foreach (['route_selected_at', 'route_selected_by', 'route'] as $column) {
            $this->dropColumn('{{%requests}}', $column);
        }
    }
}

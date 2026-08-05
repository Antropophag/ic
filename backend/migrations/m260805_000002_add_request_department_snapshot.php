<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260805_000002_add_request_department_snapshot extends Migration
{
    public function safeUp(): void
    {
        if (!$this->columnExists('department_name')) {
            $this->addColumn('{{%requests}}', 'department_name', $this->string(255)->null()->after('initiator_id'));
        }
        if (!$this->columnExists('department_external_id')) {
            $this->addColumn('{{%requests}}', 'department_external_id', $this->string(128)->null()->after('department_name'));
        }
        if (!$this->columnExists('department_source')) {
            $this->addColumn('{{%requests}}', 'department_source', $this->string(32)->notNull()->defaultValue('unknown')->after('department_external_id'));
        }

        $this->execute(
            'UPDATE {{%requests}} r JOIN {{%users}} u ON u.id = r.initiator_id '
            . "SET r.department_name = NULLIF(TRIM(u.department), ''), "
            . "r.department_source = CASE WHEN NULLIF(TRIM(u.department), '') IS NULL "
            . "THEN 'unknown' ELSE 'migration_current_profile' END "
            . "WHERE r.department_name IS NULL AND r.department_external_id IS NULL "
            . "AND r.department_source = 'unknown'"
        );
    }

    public function safeDown(): void
    {
        foreach (['department_source', 'department_external_id', 'department_name'] as $column) {
            if ($this->columnExists($column)) {
                $this->dropColumn('{{%requests}}', $column);
            }
        }
    }

    private function columnExists(string $column): bool
    {
        $table = $this->db->getSchema()->getTableSchema('{{%requests}}', true);

        return $table !== null && isset($table->columns[$column]);
    }
}

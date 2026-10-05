<?php

declare(strict_types=1);

use yii\db\Migration;

final class m261005_000003_create_security_decision_corrections extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%security_decision_corrections}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'security_check_id' => $this->bigInteger()->unsigned()->notNull(),
            'actor_id' => $this->bigInteger()->unsigned()->notNull(),
            'previous_decision' => $this->string(16)->notNull(),
            'decision' => $this->string(16)->notNull(),
            'reason' => $this->text()->notNull(),
            'ticket_reference' => $this->string(1000)->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
        $this->createIndex('idx_security_correction_check', '{{%security_decision_corrections}}', ['security_check_id', 'id']);
        $this->addForeignKey('fk_security_correction_check', '{{%security_decision_corrections}}', 'security_check_id', '{{%security_checks}}', 'id', 'RESTRICT');
        $this->addForeignKey('fk_security_correction_actor', '{{%security_decision_corrections}}', 'actor_id', '{{%users}}', 'id', 'RESTRICT');
    }

    public function safeDown(): void
    {
        if ($this->db->createCommand('SELECT 1 FROM {{%security_decision_corrections}} LIMIT 1')->queryScalar() !== false) {
            throw new RuntimeException('Security corrections are in use; restore the pre-migration backup to preserve decision history.');
        }
        $this->dropTable('{{%security_decision_corrections}}');
    }
}

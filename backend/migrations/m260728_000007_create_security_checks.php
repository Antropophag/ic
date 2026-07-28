<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000007_create_security_checks extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%security_checks}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'request_id' => $this->bigInteger()->unsigned()->notNull(),
            'expert_opinion_id' => $this->bigInteger()->unsigned()->notNull(),
            'officer_id' => $this->bigInteger()->unsigned()->notNull(),
            'decision' => $this->string(16)->notNull(),
            'reason' => $this->text(),
            'created_at' => $this->dateTime(6)->notNull(),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
        $this->addForeignKey('fk_security_check_request', '{{%security_checks}}', 'request_id', '{{%requests}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_security_check_opinion', '{{%security_checks}}', 'expert_opinion_id', '{{%expert_opinions}}', 'id', 'RESTRICT');
        $this->addForeignKey('fk_security_check_officer', '{{%security_checks}}', 'officer_id', '{{%users}}', 'id', 'RESTRICT');
        $this->createIndex('uq_security_check_opinion', '{{%security_checks}}', 'expert_opinion_id', true);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%security_checks}}');
    }
}

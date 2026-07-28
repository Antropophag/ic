<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000006_create_expert_opinions extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%expert_opinions}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'request_id' => $this->bigInteger()->unsigned()->notNull(),
            'revision' => $this->integer()->unsigned()->notNull(),
            'expert_id' => $this->bigInteger()->unsigned()->notNull(),
            'body' => $this->text()->notNull(),
            'document_version_id' => $this->bigInteger()->unsigned()->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
        $this->addForeignKey('fk_opinion_request', '{{%expert_opinions}}', 'request_id', '{{%requests}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_opinion_expert', '{{%expert_opinions}}', 'expert_id', '{{%users}}', 'id', 'RESTRICT');
        $this->addForeignKey('fk_opinion_document_version', '{{%expert_opinions}}', 'document_version_id', '{{%request_document_versions}}', 'id', 'RESTRICT');
        $this->createIndex('uq_opinion_request_revision', '{{%expert_opinions}}', ['request_id', 'revision'], true);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%expert_opinions}}');
    }
}

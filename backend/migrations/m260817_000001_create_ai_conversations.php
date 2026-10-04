<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260817_000001_create_ai_conversations extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%ai_conversations}}', [
            'id' => $this->char(32)->notNull(),
            'request_id' => $this->bigInteger()->unsigned()->notNull(),
            'document_version_id' => $this->bigInteger()->unsigned()->notNull(),
            'actor_id' => $this->bigInteger()->unsigned()->notNull(),
            'liza_chat_id' => $this->string(64)->notNull(),
            'parent_message_id' => $this->string(64)->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
            'updated_at' => $this->dateTime(6)->notNull(),
            'PRIMARY KEY ([[id]])',
        ]);
        $this->addForeignKey('fk_ai_conversation_request', '{{%ai_conversations}}', 'request_id', '{{%requests}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_ai_conversation_document_version', '{{%ai_conversations}}', 'document_version_id', '{{%request_document_versions}}', 'id', 'RESTRICT');
        $this->addForeignKey('fk_ai_conversation_actor', '{{%ai_conversations}}', 'actor_id', '{{%users}}', 'id', 'RESTRICT');
        $this->createIndex('idx_ai_conversation_owner', '{{%ai_conversations}}', ['request_id', 'actor_id', 'created_at']);

    }

    public function safeDown(): void
    {
        $this->dropTable('{{%ai_conversations}}');
    }
}

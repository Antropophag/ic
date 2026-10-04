<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260817_000002_create_ai_request_lifecycle extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%ai_idempotency_requests}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'actor_id' => $this->bigInteger()->unsigned()->notNull(),
            'http_method' => $this->string(10)->notNull(),
            'route' => $this->string(255)->notNull(),
            'key_hash' => $this->char(64)->notNull(),
            'request_hash' => $this->char(64)->notNull(),
            'state' => $this->string(16)->notNull(),
            'status_code' => $this->smallInteger()->unsigned(),
            'response_json' => 'mediumtext',
            'location' => $this->string(2048),
            'lease_expires_at' => $this->dateTime(6)->notNull(),
            'expires_at' => $this->dateTime(6)->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
            'updated_at' => $this->dateTime(6)->notNull(),
        ]);
        $this->addForeignKey('fk_ai_idempotency_actor', '{{%ai_idempotency_requests}}', 'actor_id', '{{%users}}', 'id', 'CASCADE');
        $this->createIndex('uq_ai_idempotency_key', '{{%ai_idempotency_requests}}', ['actor_id', 'http_method', 'route', 'key_hash'], true);
        $this->createIndex('idx_ai_idempotency_capacity', '{{%ai_idempotency_requests}}', ['state', 'lease_expires_at', 'actor_id']);
        $this->createIndex('idx_ai_idempotency_retention', '{{%ai_idempotency_requests}}', 'expires_at');

        $this->createTable('{{%ai_file_cleanup}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'external_file_id' => $this->string(128)->notNull(),
            'attempts' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'next_attempt_at' => $this->dateTime(6)->notNull(),
            'expires_at' => $this->dateTime(6)->notNull(),
            'last_error_class' => $this->string(255),
            'created_at' => $this->dateTime(6)->notNull(),
            'updated_at' => $this->dateTime(6)->notNull(),
        ]);
        $this->createIndex('uq_ai_file_cleanup_external', '{{%ai_file_cleanup}}', 'external_file_id', true);
        $this->createIndex('idx_ai_file_cleanup_available', '{{%ai_file_cleanup}}', ['next_attempt_at', 'expires_at']);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%ai_file_cleanup}}');
        $this->dropTable('{{%ai_idempotency_requests}}');
    }
}

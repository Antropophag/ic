<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260805_000003_create_idempotency_requests extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%idempotency_requests}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'actor_id' => $this->bigInteger()->unsigned()->notNull(),
            'http_method' => $this->string(8)->notNull(),
            'route' => $this->string(255)->notNull(),
            'key_hash' => $this->char(64)->notNull(),
            'request_hash' => $this->char(64)->notNull(),
            'status_code' => $this->smallInteger()->unsigned(),
            'response_json' => $this->text(),
            'location' => $this->string(2048),
            'created_at' => $this->dateTime(6)->notNull(),
            'expires_at' => $this->dateTime(6)->notNull(),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
        $this->addForeignKey(
            'fk_idempotency_actor',
            '{{%idempotency_requests}}',
            'actor_id',
            '{{%users}}',
            'id',
            'CASCADE',
        );
        $this->createIndex(
            'uq_idempotency_scope',
            '{{%idempotency_requests}}',
            ['actor_id', 'http_method', 'route', 'key_hash'],
            true,
        );
        $this->createIndex('idx_idempotency_expiry', '{{%idempotency_requests}}', 'expires_at');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%idempotency_requests}}');
    }
}

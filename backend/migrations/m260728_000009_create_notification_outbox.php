<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000009_create_notification_outbox extends Migration
{
    public function safeUp(): void
    {
        $options = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        $this->createTable('{{%notification_outbox}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'request_id' => $this->bigInteger()->unsigned()->notNull(),
            'event_type' => $this->string(64)->notNull(),
            'recipient_email' => $this->string(255)->notNull(),
            'recipient_name' => $this->string(255)->notNull(),
            'subject' => $this->string(255)->notNull(),
            'body' => $this->text()->notNull(),
            // NTF-003: pending -> sending -> sent | pending (повтор) | failed.
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'attempts' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            // Не раньше этого момента запись доступна для (повторного) захвата;
            // используется и для backoff после ошибки, и как аренда захвата
            // (claim lease) — просроченный sending считается зависшим.
            'next_attempt_at' => $this->dateTime(6)->notNull(),
            'last_error' => $this->text(),
            'created_at' => $this->dateTime(6)->notNull(),
            'sent_at' => $this->dateTime(6),
        ], $options);
        $this->addForeignKey(
            'fk_notification_request',
            '{{%notification_outbox}}',
            'request_id',
            '{{%requests}}',
            'id',
            'CASCADE',
        );
        $this->createIndex(
            'idx_notification_status_next_attempt',
            '{{%notification_outbox}}',
            ['status', 'next_attempt_at'],
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%notification_outbox}}');
    }
}

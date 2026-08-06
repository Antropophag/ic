<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260805_000001_create_break_glass_rate_limits extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%break_glass_rate_limits}}', [
            'scope_key' => $this->string(67)->notNull(),
            'failure_count' => $this->integer()->unsigned()->notNull(),
            'window_started_at' => $this->dateTime(6)->notNull(),
            'updated_at' => $this->dateTime(6)->notNull(),
            'PRIMARY KEY ([[scope_key]])',
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
        $this->createIndex('idx_break_glass_rate_updated', '{{%break_glass_rate_limits}}', 'updated_at');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%break_glass_rate_limits}}');
    }
}

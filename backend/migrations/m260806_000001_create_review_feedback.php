<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260806_000001_create_review_feedback extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%review_feedback}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'author_id' => $this->bigInteger()->unsigned()->notNull(),
            'body' => $this->text()->notNull(),
            'checklist_json' => $this->json()->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
        $this->addForeignKey(
            'fk_review_feedback_author',
            '{{%review_feedback}}',
            'author_id',
            '{{%users}}',
            'id',
            'RESTRICT',
        );
        $this->createIndex('idx_review_feedback_created', '{{%review_feedback}}', ['created_at', 'id']);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%review_feedback}}');
    }
}

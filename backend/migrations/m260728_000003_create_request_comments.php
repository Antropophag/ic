<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000003_create_request_comments extends Migration
{
    public function safeUp(): void
    {
        $options = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        $this->createTable('{{%request_comments}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'request_id' => $this->bigInteger()->unsigned()->notNull(),
            'author_id' => $this->bigInteger()->unsigned()->notNull(),
            'body' => $this->text()->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
        ], $options);
        $this->addForeignKey('fk_comment_request', '{{%request_comments}}', 'request_id', '{{%requests}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_comment_author', '{{%request_comments}}', 'author_id', '{{%users}}', 'id', 'RESTRICT');
        $this->createIndex('idx_comment_request_created', '{{%request_comments}}', ['request_id', 'created_at', 'id']);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%request_comments}}');
    }
}

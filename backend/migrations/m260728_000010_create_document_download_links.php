<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000010_create_document_download_links extends Migration
{
    public function safeUp(): void
    {
        $options = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        $this->createTable('{{%document_download_links}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'document_version_id' => $this->bigInteger()->unsigned()->notNull(),
            'token_hash' => $this->char(64)->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
        ], $options);
        $this->addForeignKey(
            'fk_document_link_version',
            '{{%document_download_links}}',
            'document_version_id',
            '{{%request_document_versions}}',
            'id',
            'CASCADE',
        );
        $this->createIndex('uq_document_link_token_hash', '{{%document_download_links}}', 'token_hash', true);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%document_download_links}}');
    }
}

<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000004_create_request_documents extends Migration
{
    public function safeUp(): void
    {
        $options = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        $this->createTable('{{%request_documents}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'request_id' => $this->bigInteger()->unsigned()->notNull(),
            'title' => $this->string(255)->notNull(),
            'created_by' => $this->bigInteger()->unsigned()->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
        ], $options);
        $this->addForeignKey('fk_document_request', '{{%request_documents}}', 'request_id', '{{%requests}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_document_creator', '{{%request_documents}}', 'created_by', '{{%users}}', 'id', 'RESTRICT');
        $this->createIndex('uq_document_request_title', '{{%request_documents}}', ['request_id', 'title'], true);

        $this->createTable('{{%request_document_versions}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'document_id' => $this->bigInteger()->unsigned()->notNull(),
            'version' => $this->integer()->unsigned()->notNull(),
            'storage_key' => $this->string(80)->notNull(),
            'original_name' => $this->string(255)->notNull(),
            'mime_type' => $this->string(100)->notNull(),
            'size_bytes' => $this->bigInteger()->unsigned()->notNull(),
            'sha256' => $this->char(64)->notNull(),
            'uploaded_by' => $this->bigInteger()->unsigned()->notNull(),
            'created_at' => $this->dateTime(6)->notNull(),
        ], $options);
        $this->addForeignKey('fk_document_version_document', '{{%request_document_versions}}', 'document_id', '{{%request_documents}}', 'id', 'CASCADE');
        $this->addForeignKey('fk_document_version_uploader', '{{%request_document_versions}}', 'uploaded_by', '{{%users}}', 'id', 'RESTRICT');
        $this->createIndex('uq_document_version', '{{%request_document_versions}}', ['document_id', 'version'], true);
        $this->createIndex('uq_document_storage_key', '{{%request_document_versions}}', 'storage_key', true);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%request_document_versions}}');
        $this->dropTable('{{%request_documents}}');
    }
}

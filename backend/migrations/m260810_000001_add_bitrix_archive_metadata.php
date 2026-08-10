<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260810_000001_add_bitrix_archive_metadata extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%requests}}', 'source', $this->string(32)->notNull()->defaultValue('local')->after('legacy_id'));
        $this->addColumn('{{%requests}}', 'is_archived', $this->boolean()->notNull()->defaultValue(false)->after('source'));
        $this->createIndex('idx_requests_archive', '{{%requests}}', ['is_archived', 'created_at']);

        $this->addColumn('{{%request_comments}}', 'legacy_id', $this->string(191)->after('id'));
        $this->createIndex('uq_request_comments_legacy_id', '{{%request_comments}}', 'legacy_id', true);

        $this->dropIndex('uq_document_request_title', '{{%request_documents}}');
        $this->addColumn('{{%request_documents}}', 'legacy_id', $this->string(191)->after('id'));
        $this->addColumn('{{%request_documents}}', 'title_discriminator', $this->string(191)->notNull()->defaultValue('local')->after('legacy_id'));
        $this->addColumn('{{%request_documents}}', 'comment_id', $this->bigInteger()->unsigned()->after('request_id'));
        $this->createIndex('uq_document_request_title', '{{%request_documents}}', ['request_id', 'title', 'title_discriminator'], true);
        $this->createIndex('uq_request_documents_legacy_id', '{{%request_documents}}', 'legacy_id', true);
        $this->addForeignKey('fk_document_comment', '{{%request_documents}}', 'comment_id', '{{%request_comments}}', 'id', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_document_comment', '{{%request_documents}}');
        $this->dropIndex('uq_request_documents_legacy_id', '{{%request_documents}}');
        $this->dropIndex('uq_document_request_title', '{{%request_documents}}');
        $this->dropColumn('{{%request_documents}}', 'comment_id');
        $this->dropColumn('{{%request_documents}}', 'title_discriminator');
        $this->dropColumn('{{%request_documents}}', 'legacy_id');
        $this->createIndex('uq_document_request_title', '{{%request_documents}}', ['request_id', 'title'], true);
        $this->dropIndex('uq_request_comments_legacy_id', '{{%request_comments}}');
        $this->dropColumn('{{%request_comments}}', 'legacy_id');
        $this->dropIndex('idx_requests_archive', '{{%requests}}');
        $this->dropColumn('{{%requests}}', 'is_archived');
        $this->dropColumn('{{%requests}}', 'source');
    }
}

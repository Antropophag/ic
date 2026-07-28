<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000005_add_document_type extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%request_documents}}',
            'document_type',
            $this->string(32)->notNull()->defaultValue('attachment')->after('request_id'),
        );
        $this->createIndex('idx_document_request_type', '{{%request_documents}}', ['request_id', 'document_type']);
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_document_request_type', '{{%request_documents}}');
        $this->dropColumn('{{%request_documents}}', 'document_type');
    }
}

<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000011_add_document_deletion extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%request_documents}}',
            'deleted_at',
            $this->dateTime(6)->null()->after('created_by'),
        );
        $this->addColumn(
            '{{%request_documents}}',
            'deleted_by',
            $this->bigInteger()->unsigned()->null()->after('deleted_at'),
        );
        $this->addForeignKey(
            'fk_document_deleted_by',
            '{{%request_documents}}',
            'deleted_by',
            '{{%users}}',
            'id',
            'SET NULL',
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_document_deleted_by', '{{%request_documents}}');
        $this->dropColumn('{{%request_documents}}', 'deleted_by');
        $this->dropColumn('{{%request_documents}}', 'deleted_at');
    }
}

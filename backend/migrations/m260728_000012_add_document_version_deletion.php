<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260728_000012_add_document_version_deletion extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%request_document_versions}}',
            'deleted_at',
            $this->dateTime(6)->null()->after('created_at'),
        );
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%request_document_versions}}', 'deleted_at');
    }
}

<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260730_000003_add_active_document_version_index extends Migration
{
    public function safeUp(): void
    {
        // Реестр и карточка резолвят «действующую
        // последнюю версию» документа через MAX(version) WHERE deleted_at IS
        // NULL — deleted_at появился позже uq_document_version(document_id,
        // version) и не входил в него, поэтому предикат не мог использовать
        // индекс (Qodo, PR #144).
        $this->createIndex(
            'idx_document_version_active',
            '{{%request_document_versions}}',
            ['document_id', 'deleted_at', 'version'],
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_document_version_active', '{{%request_document_versions}}');
    }
}

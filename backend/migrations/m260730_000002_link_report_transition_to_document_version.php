<?php

declare(strict_types=1);

use yii\db\Migration;

final class m260730_000002_link_report_transition_to_document_version extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%request_transitions}}',
            'document_version_id',
            $this->bigInteger()->unsigned()->null()->after('reason'),
        );
        $this->createIndex(
            'idx_transition_document_version',
            '{{%request_transitions}}',
            'document_version_id',
        );
        $this->addForeignKey(
            'fk_transition_document_version',
            '{{%request_transitions}}',
            'document_version_id',
            '{{%request_document_versions}}',
            'id',
            'SET NULL',
        );
        $this->createIndex(
            'idx_document_version_created_at',
            '{{%request_document_versions}}',
            ['document_id', 'created_at', 'id'],
        );
        // Existing upload events predate the explicit relation. Associate
        // each one with the latest report version at or before the event,
        // falling back to the earliest version after it. Future events are
        // linked directly at insert time.
        $this->execute(
            "UPDATE {{%request_transitions}} transition_event SET document_version_id = COALESCE(("
            . 'SELECT version.id FROM {{%request_document_versions}} version '
            . 'JOIN {{%request_documents}} document ON document.id = version.document_id '
            . "WHERE document.request_id = transition_event.request_id AND document.document_type = 'report' "
            . 'AND version.created_at <= transition_event.created_at '
            . 'ORDER BY version.created_at DESC, version.id DESC LIMIT 1), ('
            . 'SELECT version.id FROM {{%request_document_versions}} version '
            . 'JOIN {{%request_documents}} document ON document.id = version.document_id '
            . "WHERE document.request_id = transition_event.request_id AND document.document_type = 'report' "
            . 'AND version.created_at > transition_event.created_at '
            . 'ORDER BY version.created_at, version.id LIMIT 1)) '
            . "WHERE transition_event.action = 'upload_report' AND transition_event.document_version_id IS NULL",
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_transition_document_version', '{{%request_transitions}}');
        $this->dropIndex('idx_transition_document_version', '{{%request_transitions}}');
        $this->dropIndex('idx_document_version_created_at', '{{%request_document_versions}}');
        $this->dropColumn('{{%request_transitions}}', 'document_version_id');
    }
}

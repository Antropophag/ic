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
        $this->addForeignKey(
            'fk_transition_document_version',
            '{{%request_transitions}}',
            'document_version_id',
            '{{%request_document_versions}}',
            'id',
            'SET NULL',
        );
        $this->createIndex(
            'idx_transition_document_version',
            '{{%request_transitions}}',
            'document_version_id',
        );
        // Existing upload events predate the explicit relation. Associate
        // each one with the closest report version of the same request;
        // future events are linked directly at insert time.
        $this->execute(
            "UPDATE {{%request_transitions}} transition_event SET document_version_id = ("
            . 'SELECT version.id FROM {{%request_document_versions}} version '
            . 'JOIN {{%request_documents}} document ON document.id = version.document_id '
            . "WHERE document.request_id = transition_event.request_id AND document.document_type = 'report' "
            . 'ORDER BY ABS(TIMESTAMPDIFF(MICROSECOND, version.created_at, transition_event.created_at)), version.id LIMIT 1) '
            . "WHERE transition_event.action = 'upload_report' AND transition_event.document_version_id IS NULL",
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_transition_document_version', '{{%request_transitions}}');
        $this->dropIndex('idx_transition_document_version', '{{%request_transitions}}');
        $this->dropColumn('{{%request_transitions}}', 'document_version_id');
    }
}

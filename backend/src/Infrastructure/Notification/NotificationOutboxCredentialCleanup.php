<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use yii\db\Connection;

final class NotificationOutboxCredentialCleanup
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function run(): void
    {
        $rows = $this->db->createCommand('SELECT id, body FROM {{%notification_outbox}} ORDER BY id')->queryAll();
        foreach ($rows as $row) {
            $body = (string) $row['body'];
            $documentLinks = [];
            preg_match_all(
                '~(?:Ссылка на (отчёт|заключение):\s*)?https?://[^\s]+/api/v1/document-links/([a-f0-9]{64})/download~u',
                $body,
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $match) {
                $tokenHash = hash('sha256', $match[2]);
                $versionId = $this->db->createCommand(
                    'SELECT document_version_id FROM {{%document_download_links}} WHERE token_hash = :token_hash',
                    [':token_hash' => $tokenHash],
                )->queryScalar();
                if ($versionId !== false) {
                    $documentLinks[] = [
                        'label' => $match[1] !== '' ? $match[1] : 'документ',
                        'documentVersionId' => (int) $versionId,
                    ];
                }
                $this->db->createCommand()->delete(
                    '{{%document_download_links}}',
                    ['token_hash' => $tokenHash],
                )->execute();
            }
            $scrubbedBody = trim((string) preg_replace(
                '~\n?(?:Ссылка на (?:отчёт|заключение):\s*)?https?://[^\s]+/api/v1/document-links/[a-f0-9]{64}/download~u',
                '',
                $body,
            ));
            $this->db->createCommand()->update('{{%notification_outbox}}', [
                'body' => $scrubbedBody,
                'payload_json' => ['documentLinks' => $documentLinks],
            ], ['id' => $row['id']])->execute();
        }
    }
}

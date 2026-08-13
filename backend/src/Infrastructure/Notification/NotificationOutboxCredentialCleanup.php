<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use yii\db\Connection;

final class NotificationOutboxCredentialCleanup
{
    private const LINK_PATTERN = '~(?:Ссылка на (отчёт|заключение):\s*)?https?://[^\s]+/api/v1/document-links/([a-f0-9]{64})/download~u';
    private const SCRUB_PATTERN = '~\n?(?:Ссылка на (?:отчёт|заключение):\s*)?https?://[^\s]+/api/v1/document-links/[a-f0-9]{64}/download~u';

    /**
     * @param null|callable(int): void $afterOutboxUpdate Test-only rollback oracle.
     * @param null|callable(int): void $afterRowCommit Test-only restart oracle.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly int $batchSize = 500,
        private readonly mixed $afterOutboxUpdate = null,
        private readonly mixed $afterRowCommit = null,
    ) {
        if ($batchSize <= 0) {
            throw new \InvalidArgumentException('Notification cleanup batch size must be positive.');
        }
    }

    public function run(): void
    {
        $lastId = 0;
        while (true) {
            $rows = $this->db->createCommand(
                'SELECT id FROM {{%notification_outbox}} '
                . "WHERE id > :last_id AND body LIKE '%/api/v1/document-links/%/download%' "
                . 'ORDER BY id LIMIT :limit',
                [':last_id' => $lastId, ':limit' => $this->batchSize],
            )->queryColumn();
            if ($rows === []) {
                return;
            }
            foreach ($rows as $id) {
                $lastId = (int) $id;
                $this->migrateRow($lastId);
                if (is_callable($this->afterRowCommit)) {
                    ($this->afterRowCommit)($lastId);
                }
            }
        }
    }

    private function migrateRow(int $id): void
    {
        $transaction = $this->db->beginTransaction();
        try {
            $row = $this->db->createCommand(
                'SELECT body, payload_json FROM {{%notification_outbox}} WHERE id = :id FOR UPDATE',
                [':id' => $id],
            )->queryOne();
            if ($row === false || !preg_match_all(self::LINK_PATTERN, (string) $row['body'], $matches, PREG_SET_ORDER)) {
                $transaction->commit();
                return;
            }

            $hasExistingPayload = $row['payload_json'] !== null;
            $documentLinks = $hasExistingPayload
                ? NotificationDocumentLinksPayload::parse($row['payload_json'])
                : [];
            $shouldBuildPayload = !$hasExistingPayload || $documentLinks === [];
            $resolvedLinks = [];
            foreach ($matches as $match) {
                $tokenHash = hash('sha256', $match[2]);
                $versionId = $this->db->createCommand(
                    'SELECT document_version_id FROM {{%document_download_links}} '
                    . 'WHERE token_hash = :token_hash FOR UPDATE',
                    [':token_hash' => $tokenHash],
                )->queryScalar();
                if ($versionId === false) {
                    throw new InvalidNotificationPayload('Legacy notification credential cannot be resolved.');
                }
                $resolvedLinks[] = [
                    'label' => $match[1] !== '' ? $match[1] : 'документ',
                    'documentVersionId' => (int) $versionId,
                ];
            }
            if ($shouldBuildPayload) {
                $documentLinks = $resolvedLinks;
            } else {
                foreach ($resolvedLinks as $resolvedLink) {
                    if (!in_array($resolvedLink, $documentLinks, true)) {
                        throw new InvalidNotificationPayload('Legacy notification conflicts with its semantic payload.');
                    }
                }
            }
            NotificationDocumentLinksPayload::parse(['documentLinks' => $documentLinks]);
            $scrubbedBody = trim((string) preg_replace(self::SCRUB_PATTERN, '', (string) $row['body']));
            $values = ['body' => $scrubbedBody];
            if ($shouldBuildPayload) {
                $values['payload_json'] = ['documentLinks' => $documentLinks];
            }
            $this->db->createCommand()->update('{{%notification_outbox}}', $values, ['id' => $id])->execute();
            if (is_callable($this->afterOutboxUpdate)) {
                ($this->afterOutboxUpdate)($id);
            }
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $error;
        }
    }
}

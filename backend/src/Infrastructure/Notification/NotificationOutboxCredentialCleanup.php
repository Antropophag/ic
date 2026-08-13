<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use yii\db\Connection;

final class NotificationOutboxCredentialCleanup
{
    private const LINK_PATTERN = '~(?:Ссылка на (отчёт|заключение):\s*)?https?://[^\s]+/api/v1/document-links/([a-f0-9]{64})/download~u';
    private const SCRUB_PATTERN = '~\n?(?:Ссылка на (?:отчёт|заключение):\s*)?https?://[^\s]+/api/v1/document-links/[a-f0-9]{64}/download~u';

    /** @param null|callable(int): void $afterOutboxUpdate Test-only fault oracle. */
    public function __construct(
        private readonly Connection $db,
        private readonly int $batchSize = 500,
        private readonly mixed $afterOutboxUpdate = null,
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

            $existingPayload = $this->decodePayload($row['payload_json']);
            $documentLinks = $existingPayload === null ? [] : $existingPayload['documentLinks'];
            $tokenHashes = [];
            foreach ($matches as $match) {
                $tokenHash = hash('sha256', $match[2]);
                $tokenHashes[] = $tokenHash;
                $versionId = $this->db->createCommand(
                    'SELECT document_version_id FROM {{%document_download_links}} WHERE token_hash = :token_hash',
                    [':token_hash' => $tokenHash],
                )->queryScalar();
                if ($versionId !== false && ($existingPayload === null || $documentLinks === [])) {
                    $documentLinks[] = [
                        'label' => $match[1] !== '' ? $match[1] : 'документ',
                        'documentVersionId' => (int) $versionId,
                    ];
                }
            }
            $scrubbedBody = trim((string) preg_replace(self::SCRUB_PATTERN, '', (string) $row['body']));
            $values = ['body' => $scrubbedBody];
            if ($existingPayload === null || $existingPayload['documentLinks'] === []) {
                $values['payload_json'] = ['documentLinks' => $documentLinks];
            }
            $this->db->createCommand()->update('{{%notification_outbox}}', $values, ['id' => $id])->execute();
            if (is_callable($this->afterOutboxUpdate)) {
                ($this->afterOutboxUpdate)($id);
            }
            foreach ($tokenHashes as $tokenHash) {
                $this->db->createCommand()->delete(
                    '{{%document_download_links}}',
                    ['token_hash' => $tokenHash],
                )->execute();
            }
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{documentLinks: list<array{label: string, documentVersionId: int}>}|null */
    private function decodePayload(mixed $payload): ?array
    {
        if ($payload === null) {
            return null;
        }
        $decoded = is_array($payload) ? $payload : json_decode((string) $payload, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['documentLinks'] ?? null)) {
            throw new InvalidNotificationPayload('Existing notification payload is malformed.');
        }
        return $decoded;
    }
}

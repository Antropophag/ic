<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Infrastructure\Document\DocumentStorage;
use RuntimeException;
use yii\db\Connection;

final class BitrixArchiveFileImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentStorage $storage,
        private readonly int $listId = 114,
    ) {
    }

    /** @return array{records: int, created: int, skipped: int, unavailable: int, unmatched: int} */
    public function import(string $workspace, bool $apply = false): array
    {
        $workspace = rtrim($workspace, DIRECTORY_SEPARATOR);
        if ($apply) {
            $this->storage->assertWritable();
        }
        $checkpoint = [];
        foreach ($this->jsonLines($workspace . '/checkpoint.jsonl') as $record) {
            $checkpoint[(string) ($record['sourceFileId'] ?? '')] = $record;
        }
        $summary = ['records' => 0, 'created' => 0, 'skipped' => 0, 'unavailable' => 0, 'unmatched' => 0];
        foreach ($this->jsonLines($workspace . '/associations.jsonl') as $association) {
            ++$summary['records'];
            $legacyId = $this->associationId($association);
            $this->assertMaximumLength($legacyId, 191, 'association legacy ID');
            if ($this->db->createCommand('SELECT 1 FROM {{%request_documents}} WHERE legacy_id = :id', [':id' => $legacyId])->queryScalar() !== false) {
                ++$summary['skipped'];
                continue;
            }
            $sourceFileId = (string) ($association['sourceFileId'] ?? '');
            if (preg_match('/\A[0-9A-Za-z_-]+\z/D', $sourceFileId) !== 1) {
                throw new RuntimeException("Unsafe source file identifier: {$sourceFileId}.");
            }
            $this->assertMaximumLength((string) ($association['originalName'] ?? ''), 255, 'original file name');
            $metadata = $checkpoint[$sourceFileId] ?? null;
            $object = $workspace . '/objects/' . $sourceFileId;
            if (
                !is_array($metadata) || ($metadata['status'] ?? null) !== 'downloaded' || !is_file($object)
                || filesize($object) !== ($metadata['bytes'] ?? null) || hash_file('sha256', $object) !== ($metadata['sha256'] ?? null)
            ) {
                ++$summary['unavailable'];
                continue;
            }
            if (!$this->hasTarget($association)) {
                ++$summary['unmatched'];
                continue;
            }
            if ($apply) {
                if (!$this->write($association, $legacyId, $object, $metadata)) {
                    ++$summary['skipped'];
                    continue;
                }
            }
            ++$summary['created'];
        }
        return $summary;
    }

    /** @param array<string, mixed> $association */
    private function hasTarget(array $association): bool
    {
        $requestLegacyId = "bitrix24:{$this->listId}:" . (string) ($association['requestNumber'] ?? '');
        if (($association['documentType'] ?? null) !== 'comment') {
            return $this->db->createCommand(
                'SELECT 1 FROM {{%requests}} WHERE legacy_id = :id AND is_archived = 1',
                [':id' => $requestLegacyId],
            )->queryScalar() !== false;
        }
        return $this->db->createCommand(
            'SELECT 1 FROM {{%request_comments}} WHERE legacy_id = :id',
            [':id' => $this->commentLegacyId($association, $requestLegacyId)],
        )->queryScalar() !== false;
    }

    /** @param array<string, mixed> $association
     *  @param array<string, mixed> $metadata
     */
    private function write(array $association, string $legacyId, string $object, array $metadata): bool
    {
        $transaction = $this->db->beginTransaction();
        $storageKey = null;
        try {
            $requestLegacyId = "bitrix24:{$this->listId}:" . (string) ($association['requestNumber'] ?? '');
            $request = $this->db->createCommand(
                'SELECT id, initiator_id, created_at FROM {{%requests}} '
                . 'WHERE legacy_id = :id AND is_archived = 1 FOR UPDATE',
                [':id' => $requestLegacyId],
            )->queryOne();
            if ($request === false) {
                throw new RuntimeException("Archived request is missing for {$requestLegacyId}.");
            }
            if (
                $this->db->createCommand(
                    'SELECT 1 FROM {{%request_documents}} WHERE legacy_id = :id',
                    [':id' => $legacyId],
                )->queryScalar() !== false
            ) {
                $transaction->commit();
                return false;
            }
            $commentId = null;
            $uploader = (int) $request['initiator_id'];
            if (($association['documentType'] ?? null) === 'comment') {
                $commentLegacyId = $this->commentLegacyId($association, $requestLegacyId);
                $comment = $this->db->createCommand(
                    'SELECT id, author_id FROM {{%request_comments}} WHERE legacy_id = :id AND request_id = :request_id',
                    [':id' => $commentLegacyId, ':request_id' => (int) $request['id']],
                )->queryOne();
                if ($comment === false) {
                    throw new RuntimeException("Imported comment is missing for {$commentLegacyId}.");
                }
                $commentId = (int) $comment['id'];
                $uploader = (int) $comment['author_id'];
            }
            $storageKey = $this->storage->store($object);
            $this->db->createCommand()->insert('{{%request_documents}}', [
                'legacy_id' => $legacyId, 'title_discriminator' => $legacyId,
                'request_id' => (int) $request['id'], 'comment_id' => $commentId,
                'document_type' => (string) $association['documentType'], 'title' => (string) $association['originalName'],
                'created_by' => $uploader, 'created_at' => (string) $request['created_at'],
            ])->execute();
            $documentId = (int) $this->db->getLastInsertID();
            $this->db->createCommand()->insert('{{%request_document_versions}}', [
                'document_id' => $documentId, 'version' => 1, 'storage_key' => $storageKey,
                'original_name' => (string) $association['originalName'],
                'mime_type' => (string) ($metadata['mime'] ?? 'application/octet-stream'),
                'size_bytes' => (int) $metadata['bytes'], 'sha256' => (string) $metadata['sha256'],
                'uploaded_by' => $uploader, 'created_at' => (string) $request['created_at'],
            ])->execute();
            $transaction->commit();
            return true;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            if ($storageKey !== null) {
                $this->storage->delete($storageKey);
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $association */
    private function commentLegacyId(array $association, string $requestLegacyId): string
    {
        return $requestLegacyId . ':comment:comments'
            . (($association['commentType'] ?? null) === 'ic' ? 'IC:' : 'Initiator:')
            . (string) ($association['sourceCommentId'] ?? '');
    }

    /** @param array<string, mixed> $association */
    private function associationId(array $association): string
    {
        return implode(':', ['bitrix24', 'file', (string) ($association['requestNumber'] ?? ''),
            (string) ($association['documentType'] ?? ''), (string) ($association['sourceCommentId'] ?? '-'),
            (string) ($association['sourceFileId'] ?? '')]);
    }

    private function assertMaximumLength(string $value, int $maximum, string $field): void
    {
        if (mb_strlen($value) > $maximum) {
            throw new RuntimeException("Legacy {$field} exceeds {$maximum} characters.");
        }
    }

    /** @return iterable<array<string, mixed>> */
    private function jsonLines(string $path): iterable
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($record)) {
                    throw new RuntimeException("Invalid record in {$path}.");
                }
                yield $record;
            }
        } finally {
            fclose($handle);
        }
    }
}

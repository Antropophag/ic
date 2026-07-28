<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

use App\Domain\Request\AttachmentPolicy;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use yii\db\Connection;

final class DocumentRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentStorage $storage,
    ) {
    }

    /** @return array<string, mixed> */
    public function upload(
        int $requestId,
        int $actorId,
        string $originalName,
        string $mimeType,
        int $size,
        string $temporaryPath,
    ): array {
        $policy = new AttachmentPolicy();
        $policy->assertValidFile($originalName, $mimeType, $size);
        $sha256 = hash_file('sha256', $temporaryPath);
        if ($sha256 === false) {
            throw new \RuntimeException('Cannot hash uploaded document.');
        }

        $transaction = $this->db->beginTransaction();
        $storageKey = null;
        try {
            $request = $this->db->createCommand(
                'SELECT r.status FROM {{%requests}} r '
                . 'JOIN {{%users}} u ON u.id = :actor_id AND u.is_active = 1 '
                . 'WHERE r.id = :request_id FOR UPDATE',
                [':request_id' => $requestId, ':actor_id' => $actorId],
            )->queryOne();
            if ($request === false) {
                throw new RequestNotFound('Request not found');
            }
            $policy->assertCanUpload(RequestStatus::from((string) $request['status']));

            $documentId = $this->findOrCreateDocument($requestId, $actorId, $originalName);
            $version = (int) $this->db->createCommand(
                'SELECT COALESCE(MAX(version), 0) + 1 FROM {{%request_document_versions}} '
                . 'WHERE document_id = :document_id',
                [':document_id' => $documentId],
            )->queryScalar();
            $storageKey = $this->storage->store($temporaryPath);
            $now = gmdate('Y-m-d H:i:s.u');
            $this->db->createCommand()->insert('{{%request_document_versions}}', [
                'document_id' => $documentId,
                'version' => $version,
                'storage_key' => $storageKey,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'uploaded_by' => $actorId,
                'created_at' => $now,
            ])->execute();
            $versionId = (int) $this->db->getLastInsertID();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.document_uploaded',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => 'COM-004',
                'payload_json' => json_encode(['document_id' => $documentId, 'version_id' => $versionId], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ])->execute();
            $transaction->commit();

            return [
                'id' => $documentId,
                'title' => $originalName,
                'versionId' => $versionId,
                'version' => $version,
                'originalName' => $originalName,
                'mimeType' => $mimeType,
                'sizeBytes' => $size,
                'sha256' => $sha256,
                'uploadedBy' => $actorId,
                'createdAt' => str_replace(' ', 'T', $now) . 'Z',
            ];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            if ($storageKey !== null) {
                $this->storage->delete($storageKey);
            }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function findVersionForDownload(int $versionId, int $actorId): array
    {
        $version = $this->db->createCommand(
            'SELECT v.id, v.storage_key AS storageKey, v.original_name AS originalName, '
            . 'v.mime_type AS mimeType, v.size_bytes AS sizeBytes, d.request_id AS requestId '
            . 'FROM {{%request_document_versions}} v '
            . 'JOIN {{%request_documents}} d ON d.id = v.document_id '
            . 'JOIN {{%users}} viewer ON viewer.id = :actor_id AND viewer.is_active = 1 '
            . 'WHERE v.id = :version_id',
            [':version_id' => $versionId, ':actor_id' => $actorId],
        )->queryOne();
        if ($version === false) {
            throw new RequestNotFound('Document version not found');
        }
        return $version;
    }

    public function recordDownload(int $versionId, int $requestId, int $actorId): void
    {
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.document_downloaded',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => 'ACL-007',
            'payload_json' => json_encode(['version_id' => $versionId], JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ])->execute();
    }

    public function recordRejectedDownload(int $versionId, int $actorId, string $reason): void
    {
        $actorExists = $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :actor_id',
            [':actor_id' => $actorId],
        )->queryScalar();
        if ($actorExists === false) {
            return;
        }
        $requestId = $this->db->createCommand(
            'SELECT d.request_id FROM {{%request_document_versions}} v '
            . 'JOIN {{%request_documents}} d ON d.id = v.document_id WHERE v.id = :version_id',
            [':version_id' => $versionId],
        )->queryScalar();
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.document_download_rejected',
            'entity_type' => $requestId === false ? 'document_version' : 'request',
            'entity_id' => $requestId === false ? $versionId : (int) $requestId,
            'actor_id' => $actorId,
            'rule_id' => 'ACL-007',
            'payload_json' => json_encode(
                ['version_id' => $versionId, 'outcome' => 'rejected', 'reason' => $reason],
                JSON_THROW_ON_ERROR,
            ),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ])->execute();
    }

    private function findOrCreateDocument(int $requestId, int $actorId, string $title): int
    {
        $documentId = $this->db->createCommand(
            'SELECT id FROM {{%request_documents}} WHERE request_id = :request_id AND title = :title FOR UPDATE',
            [':request_id' => $requestId, ':title' => $title],
        )->queryScalar();
        if ($documentId !== false) {
            return (int) $documentId;
        }
        $this->db->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId,
            'title' => $title,
            'created_by' => $actorId,
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }
}

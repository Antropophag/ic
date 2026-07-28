<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

use App\Domain\Request\AttachmentPolicy;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\ReportPolicy;
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
    public function uploadReport(
        int $requestId,
        int $actorId,
        string $originalName,
        string $mimeType,
        int $size,
        string $temporaryPath,
    ): array {
        $policy = new ReportPolicy();
        $policy->assertValidFile($originalName, $mimeType, $size);
        $sha256 = hash_file('sha256', $temporaryPath);
        if ($sha256 === false) {
            throw new \RuntimeException('Cannot hash uploaded report.');
        }

        $transaction = $this->db->beginTransaction();
        $storageKey = null;
        try {
            $request = $this->db->createCommand(
                'SELECT r.status, r.lock_version AS lockVersion, '
                . '(executor.user_id = :executor_actor) AS isExecutor, '
                . "EXISTS(SELECT 1 FROM {{%user_roles}} ur JOIN {{%roles}} role ON role.id = ur.role_id "
                . "WHERE ur.user_id = :manager_actor AND role.code IN ('ic_manager', 'laboratory_manager')) AS isManager "
                . 'FROM {{%requests}} r JOIN {{%users}} actor ON actor.id = :actor_id AND actor.is_active = 1 '
                . 'LEFT JOIN {{%request_assignments}} executor ON executor.request_id = r.id '
                . "AND executor.assignment_type = 'executor' AND executor.valid_to IS NULL "
                . 'WHERE r.id = :request_id FOR UPDATE',
                [
                    ':request_id' => $requestId,
                    ':actor_id' => $actorId,
                    ':executor_actor' => $actorId,
                    ':manager_actor' => $actorId,
                ],
            )->queryOne();
            if ($request === false) {
                throw new RequestNotFound('Request not found');
            }
            $status = RequestStatus::from((string) $request['status']);
            $policy->assertCanUpload($status, (bool) $request['isExecutor'], (bool) $request['isManager']);

            $documentId = $this->findOrCreateReport($requestId, $actorId);
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
            $nextLockVersion = (int) $request['lockVersion'] + ($version === 1 ? 1 : 0);
            if ($version === 1) {
                $targetStatus = $status === RequestStatus::InProgress
                    ? RequestStatus::OpinionPreparation
                    : $status;
                $updated = $this->db->createCommand()->update('{{%requests}}', [
                    'status' => $targetStatus->value,
                    'lock_version' => $nextLockVersion,
                    'updated_at' => $now,
                ], [
                    'id' => $requestId,
                    'status' => $status->value,
                    'lock_version' => (int) $request['lockVersion'],
                ])->execute();
                if ($updated !== 1) {
                    throw new \RuntimeException('Concurrent report upload detected.');
                }
            }
            if ($status === RequestStatus::InProgress) {
                $this->db->createCommand()->insert('{{%request_transitions}}', [
                    'request_id' => $requestId,
                    'actor_id' => $actorId,
                    'from_status' => RequestStatus::InProgress->value,
                    'to_status' => RequestStatus::OpinionPreparation->value,
                    'action' => 'upload_report',
                    'rule_id' => 'DOC-002',
                    'created_at' => $now,
                ])->execute();
            }
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.report_uploaded',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => $version === 1 ? 'DOC-002' : 'DOC-008',
                'payload_json' => json_encode(
                    ['document_id' => $documentId, 'version_id' => $versionId, 'version' => $version],
                    JSON_THROW_ON_ERROR,
                ),
                'created_at' => $now,
            ])->execute();
            $transaction->commit();

            return [
                'id' => $documentId,
                'documentType' => 'report',
                'title' => 'Отчёт испытаний',
                'versionId' => $versionId,
                'version' => $version,
                'originalName' => $originalName,
                'mimeType' => $mimeType,
                'sizeBytes' => $size,
                'sha256' => $sha256,
                'uploadedBy' => $actorId,
                'createdAt' => str_replace(' ', 'T', $now) . 'Z',
                'status' => $status === RequestStatus::InProgress
                    ? RequestStatus::OpinionPreparation->value
                    : $status->value,
                'lockVersion' => $nextLockVersion,
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
            . 'JOIN {{%requests}} r ON r.id = d.request_id '
            . 'JOIN {{%users}} viewer ON viewer.id = :actor_id AND viewer.is_active = 1 '
            . 'LEFT JOIN {{%request_assignments}} executor ON executor.request_id = r.id '
            . "AND executor.assignment_type = 'executor' AND executor.valid_to IS NULL "
            . "WHERE v.id = :version_id AND (d.document_type <> 'report' "
            . "OR (r.status = 'completed' AND v.version = (SELECT MAX(public_version.version) "
            . 'FROM {{%request_document_versions}} public_version WHERE public_version.document_id = d.id)) '
            . 'OR executor.user_id = :report_actor OR EXISTS(SELECT 1 FROM {{%user_roles}} ur '
            . 'JOIN {{%roles}} role ON role.id = ur.role_id WHERE ur.user_id = :manager_actor '
            . "AND role.code IN ('ic_manager', 'laboratory_manager')))",
            [
                ':version_id' => $versionId,
                ':actor_id' => $actorId,
                ':report_actor' => $actorId,
                ':manager_actor' => $actorId,
            ],
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

    public function recordRejectedReportUpload(int $requestId, int $actorId, string $ruleId): void
    {
        $allowedReferences = $this->db->createCommand(
            'SELECT 1 FROM {{%requests}} r JOIN {{%users}} actor ON actor.id = :actor_id '
            . 'WHERE r.id = :request_id',
            [':request_id' => $requestId, ':actor_id' => $actorId],
        )->queryScalar();
        if ($allowedReferences === false) {
            return;
        }
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.report_upload_rejected',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => json_encode(['outcome' => 'rejected'], JSON_THROW_ON_ERROR),
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

    private function findOrCreateReport(int $requestId, int $actorId): int
    {
        $documentId = $this->db->createCommand(
            "SELECT id FROM {{%request_documents}} WHERE request_id = :request_id AND document_type = 'report' FOR UPDATE",
            [':request_id' => $requestId],
        )->queryScalar();
        if ($documentId !== false) {
            return (int) $documentId;
        }
        $this->db->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId,
            'document_type' => 'report',
            'title' => 'Отчёт испытаний',
            'created_by' => $actorId,
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }
}

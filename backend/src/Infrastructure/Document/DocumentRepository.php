<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

use App\Domain\Request\AttachmentPolicy;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\ReportDeletionPolicy;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\ReportPolicy;
use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutbox;
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
            $now = Clock::now();
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
                'payload_json' => ['document_id' => $documentId, 'version_id' => $versionId],
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
                . "WHERE ur.user_id = :manager_actor AND role.code IN ('ic_manager', 'laboratory_manager')) AS isManager, "
                . "EXISTS(SELECT 1 FROM {{%request_documents}} existing_report WHERE existing_report.request_id = r.id "
                . "AND existing_report.document_type = 'report' AND existing_report.deleted_at IS NULL) AS hasActiveReport "
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
            $policy->assertCanUpload(
                $status,
                (bool) $request['isExecutor'],
                (bool) $request['isManager'],
                (bool) $request['hasActiveReport'],
            );

            $report = $this->findOrCreateReport($requestId, $actorId);
            $documentId = $report['id'];
            $version = (int) $this->db->createCommand(
                'SELECT COALESCE(MAX(version), 0) + 1 FROM {{%request_document_versions}} '
                . 'WHERE document_id = :document_id',
                [':document_id' => $documentId],
            )->queryScalar();
            $storageKey = $this->storage->store($temporaryPath);
            $now = Clock::now();
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
            // Загрузка отчёта в статусе "в работе" всегда переводит заявку на
            // подготовку заключения (независимо от номера версии — так и
            // возврат отчёта на доработку после ✕ СБ снова запускает цикл).
            // ТЗ 7.8: то же самое происходит и при повторной загрузке после
            // удаления отчёта (DOC-011), даже если заявка уже была выполнена.
            $isFirstOrRevived = $version === 1 || $report['wasDeleted'];
            $statusChanges = $status === RequestStatus::InProgress
                || ($report['wasDeleted'] && $status !== RequestStatus::OpinionPreparation);
            $requestChanges = $isFirstOrRevived || $statusChanges;
            $nextLockVersion = (int) $request['lockVersion'] + ($requestChanges ? 1 : 0);
            $targetStatus = $statusChanges ? RequestStatus::OpinionPreparation : $status;
            if ($requestChanges) {
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
            if ($statusChanges) {
                $this->db->createCommand()->insert('{{%request_transitions}}', [
                    'request_id' => $requestId,
                    'actor_id' => $actorId,
                    'from_status' => $status->value,
                    'to_status' => RequestStatus::OpinionPreparation->value,
                    'action' => 'upload_report',
                    'rule_id' => $report['wasDeleted'] ? 'DOC-012' : 'DOC-002',
                    'document_version_id' => $versionId,
                    'created_at' => $now,
                ])->execute();
            }
            if ($targetStatus === RequestStatus::OpinionPreparation) {
                // ТЗ 4.6/4.7: отчёт (первая версия или замена по DOC-008)
                // поступил на подготовку заключения — уведомляются все
                // активные эксперты (никто конкретно заявку не назначает,
                // первый взявший её в работу и формирует заключение, см.
                // WF-010).
                $outbox = new NotificationOutbox($this->db);
                foreach ($this->activeUsersWithRoles(['expert']) as $expert) {
                    $outbox->enqueue(
                        $requestId,
                        'request.report_uploaded',
                        $expert['email'],
                        $expert['name'],
                        'Поступил отчёт для подготовки заключения',
                        'Загружен отчёт испытаний, для которого нужно подготовить экспертное заключение. '
                        . 'Откройте заявку в портале и возьмите её в работу.',
                        [['label' => 'отчёт', 'documentVersionId' => $versionId]],
                    );
                }
            }
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.report_uploaded',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => $isFirstOrRevived ? ($report['wasDeleted'] ? 'DOC-012' : 'DOC-002') : 'DOC-008',
                'payload_json' => ['document_id' => $documentId, 'version_id' => $versionId, 'version' => $version],
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
                'status' => $targetStatus->value,
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

    /** @return array{status: string, lockVersion: int} */
    public function deleteReport(int $requestId, int $expectedLockVersion, int $actorId, string $reason): array
    {
        $transaction = $this->db->beginTransaction();
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
            $document = $this->db->createCommand(
                "SELECT id FROM {{%request_documents}} WHERE request_id = :request_id "
                . "AND document_type = 'report' AND deleted_at IS NULL FOR UPDATE",
                [':request_id' => $requestId],
            )->queryOne();
            (new ReportDeletionPolicy())->assertCanDelete(
                (bool) $request['isExecutor'],
                (bool) $request['isManager'],
                $document !== false,
            );
            if ((int) $request['lockVersion'] !== $expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }

            $now = Clock::now();
            // DOC-011: версии удалённого отчёта помечаются удалёнными
            // безвозвратно — при повторной загрузке "оживает" только сам
            // документ (иначе не даёт создать новую запись уникальность
            // request_id+title), но старые ревизии и выданные на них
            // email-ссылки не должны снова становиться доступны.
            $this->db->createCommand()->update('{{%request_document_versions}}', [
                'deleted_at' => $now,
            ], ['document_id' => (int) $document['id'], 'deleted_at' => null])->execute();
            $this->db->createCommand()->update('{{%request_documents}}', [
                'deleted_at' => $now,
                'deleted_by' => $actorId,
            ], ['id' => (int) $document['id']])->execute();
            $nextLockVersion = $expectedLockVersion + 1;
            $updated = $this->db->createCommand()->update('{{%requests}}', [
                'status' => RequestStatus::InProgress->value,
                'lock_version' => $nextLockVersion,
                'updated_at' => $now,
            ], [
                'id' => $requestId,
                'lock_version' => $expectedLockVersion,
            ])->execute();
            if ($updated !== 1) {
                throw new ConcurrentRequestModification();
            }
            $status = RequestStatus::from((string) $request['status']);
            if ($status !== RequestStatus::InProgress) {
                $this->db->createCommand()->insert('{{%request_transitions}}', [
                    'request_id' => $requestId,
                    'actor_id' => $actorId,
                    'from_status' => $status->value,
                    'to_status' => RequestStatus::InProgress->value,
                    'action' => 'delete_report',
                    'rule_id' => 'DOC-011',
                    'reason' => $reason,
                    'created_at' => $now,
                ])->execute();
            }
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.report_deleted',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => 'DOC-011',
                'payload_json' => ['document_id' => (int) $document['id'], 'reason' => $reason],
                'created_at' => $now,
            ])->execute();
            $transaction->commit();

            return ['status' => RequestStatus::InProgress->value, 'lockVersion' => $nextLockVersion];
        } catch (\Throwable $error) {
            $transaction->rollBack();
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
            . "WHERE v.id = :version_id AND d.deleted_at IS NULL AND v.deleted_at IS NULL "
            . "AND (d.document_type NOT IN ('report', 'opinion') "
            . "OR (d.document_type = 'report' AND ((r.status = 'completed' AND v.version = (SELECT MAX(public_version.version) "
            . 'FROM {{%request_document_versions}} public_version WHERE public_version.document_id = d.id)) '
            . 'OR executor.user_id = :report_actor OR EXISTS(SELECT 1 FROM {{%user_roles}} ur '
            . 'JOIN {{%roles}} role ON role.id = ur.role_id WHERE ur.user_id = :manager_actor '
            . "AND role.code IN ('ic_manager', 'laboratory_manager')) "
            . 'OR EXISTS(SELECT 1 FROM {{%request_assignments}} ra WHERE ra.request_id = r.id '
            . "AND ra.assignment_type = 'expert' AND ra.valid_to IS NULL AND ra.user_id = :report_expert_actor))) "
            . "OR (d.document_type = 'opinion' AND ((r.status = 'completed' AND v.version = (SELECT MAX(public_opinion.version) "
            . 'FROM {{%request_document_versions}} public_opinion WHERE public_opinion.document_id = d.id)) '
            . 'OR EXISTS(SELECT 1 FROM {{%request_assignments}} oa WHERE oa.request_id = r.id '
            . "AND oa.assignment_type = 'expert' AND oa.valid_to IS NULL AND oa.user_id = :opinion_actor) "
            . 'OR EXISTS(SELECT 1 FROM {{%expert_opinions}} eo WHERE eo.document_version_id = v.id '
            . 'AND eo.expert_id = :opinion_author) OR EXISTS(SELECT 1 FROM {{%user_roles}} our '
            . 'JOIN {{%roles}} opinion_role ON opinion_role.id = our.role_id WHERE our.user_id = :opinion_privileged '
            . "AND opinion_role.code IN ('ic_manager', 'laboratory_manager', 'security_officer')))))",
            [
                ':version_id' => $versionId,
                ':actor_id' => $actorId,
                ':report_actor' => $actorId,
                ':manager_actor' => $actorId,
                ':report_expert_actor' => $actorId,
                ':opinion_actor' => $actorId,
                ':opinion_author' => $actorId,
                ':opinion_privileged' => $actorId,
            ],
        )->queryOne();
        if ($version === false) {
            throw new RequestNotFound('Document version not found');
        }
        return $version;
    }

    /** @return array<string, mixed>|false */
    public function findVersionByToken(string $token): array|false
    {
        return $this->db->createCommand(
            'SELECT v.storage_key AS storageKey, v.original_name AS originalName, v.mime_type AS mimeType '
            . 'FROM {{%document_download_links}} l '
            . 'JOIN {{%request_document_versions}} v ON v.id = l.document_version_id '
            . 'JOIN {{%request_documents}} d ON d.id = v.document_id '
            . 'WHERE l.token_hash = :hash AND d.deleted_at IS NULL AND v.deleted_at IS NULL',
            [':hash' => hash('sha256', $token)],
        )->queryOne();
    }

    public function recordDownload(int $versionId, int $requestId, int $actorId): void
    {
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.document_downloaded',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => 'ACL-007',
            'payload_json' => ['version_id' => $versionId],
            'created_at' => Clock::now(),
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
            'payload_json' => ['version_id' => $versionId, 'outcome' => 'rejected', 'reason' => $reason],
            'created_at' => Clock::now(),
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
            'payload_json' => ['outcome' => 'rejected'],
            'created_at' => Clock::now(),
        ])->execute();
    }

    public function recordRejectedReportDeletion(int $requestId, int $actorId, string $ruleId): void
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
            'event_type' => 'request.report_deletion_rejected',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => ['outcome' => 'rejected'],
            'created_at' => Clock::now(),
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
            'created_at' => Clock::now(),
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    /** @return array{id: int, wasDeleted: bool} */
    private function findOrCreateReport(int $requestId, int $actorId): array
    {
        // uq_document_request_title запрещает вторую строку 'Отчёт испытаний'
        // для той же заявки, поэтому после удаления (DOC-011) строка не
        // создаётся заново, а оживает — deleted_at сбрасывается.
        $document = $this->db->createCommand(
            "SELECT id, deleted_at AS deletedAt FROM {{%request_documents}} "
            . "WHERE request_id = :request_id AND document_type = 'report' FOR UPDATE",
            [':request_id' => $requestId],
        )->queryOne();
        if ($document !== false) {
            $wasDeleted = $document['deletedAt'] !== null;
            if ($wasDeleted) {
                $this->db->createCommand()->update('{{%request_documents}}', [
                    'deleted_at' => null,
                    'deleted_by' => null,
                ], ['id' => (int) $document['id']])->execute();
            }
            return ['id' => (int) $document['id'], 'wasDeleted' => $wasDeleted];
        }
        $this->db->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId,
            'document_type' => 'report',
            'title' => 'Отчёт испытаний',
            'created_by' => $actorId,
            'created_at' => Clock::now(),
        ])->execute();
        return ['id' => (int) $this->db->getLastInsertID(), 'wasDeleted' => false];
    }

    /**
     * @param list<string> $roleCodes
     * @return list<array{email: string, name: string}>
     */
    private function activeUsersWithRoles(array $roleCodes): array
    {
        if ($roleCodes === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($roleCodes as $index => $code) {
            $placeholders[] = ":role{$index}";
            $params[":role{$index}"] = $code;
        }

        return $this->db->createCommand(
            'SELECT DISTINCT TRIM(u.email) AS email, u.display_name AS name FROM {{%users}} u '
            . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id '
            . 'JOIN {{%roles}} r ON r.id = ur.role_id '
            . "WHERE u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != '' "
            . 'AND r.code IN (' . implode(',', $placeholders) . ')',
            $params,
        )->queryAll();
    }
}

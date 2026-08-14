<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Request;

use App\Application\Request\Command\DeleteReportCommand;
use App\Application\Request\Command\UploadReportCommand;
use App\Application\Request\DeleteReportResult;
use App\Application\Request\Port\ReportLifecycleGateway;
use App\Application\Request\ReportLifecycleSnapshot;
use App\Application\Request\UploadReportResult;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestStatus;
use App\Infrastructure\Clock;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Notification\NotificationOutbox;
use yii\db\Connection;

final readonly class ReportLifecyclePersistenceAdapter implements ReportLifecycleGateway
{
    public function __construct(private Connection $db, private DocumentStorage $storage)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $transaction = $this->db->beginTransaction();
        $storageCheckpoint = DocumentStorage::writeCheckpoint();
        try {
            $result = $operation();
            $transaction->commit();
            DocumentStorage::discardWritesSince($storageCheckpoint);
            return $result;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            DocumentStorage::rollbackWritesSince($storageCheckpoint);
            throw $error;
        }
    }

    public function hashUploadedFile(string $temporaryPath): string
    {
        $sha256 = hash_file('sha256', $temporaryPath);
        if ($sha256 === false) {
            throw new \RuntimeException('Cannot hash uploaded report.');
        }
        return $sha256;
    }

    public function snapshotForUpdate(int $requestId, int $actorId): ?ReportLifecycleSnapshot
    {
        $row = $this->db->createCommand(
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
        if ($row === false) {
            return null;
        }
        return new ReportLifecycleSnapshot(
            RequestStatus::from((string) $row['status']),
            (int) $row['lockVersion'],
            (bool) $row['isExecutor'],
            (bool) $row['isManager'],
            (bool) $row['hasActiveReport'],
        );
    }

    public function persistUpload(
        UploadReportCommand $command,
        ReportLifecycleSnapshot $snapshot,
        string $sha256,
    ): UploadReportResult {
        $report = $this->findOrCreateReport($command->requestId, $command->actorId);
        $version = $this->nextReportVersion($report['id']);
        $storageKey = $this->storage->store($command->temporaryPath);
        $now = Clock::now();
        $versionId = $this->insertReportVersion($command, $report['id'], $version, $storageKey, $sha256, $now);
        $state = $this->persistUploadWorkflow($command, $snapshot, $report['wasDeleted'], $version, $versionId, $now);
        if ($state['targetStatus'] === RequestStatus::OpinionPreparation) {
            $this->enqueueUploadNotifications($command->requestId, $versionId);
        }
        $this->recordSuccessfulUpload(
            $command,
            $report['id'],
            $versionId,
            $version,
            $report['wasDeleted'],
            $state['isFirstOrRevived'],
            $now,
        );

        return new UploadReportResult(
            $report['id'],
            $versionId,
            $version,
            $command->originalName,
            $command->mimeType,
            $command->size,
            $sha256,
            $command->actorId,
            $now,
            $state['targetStatus'],
            $state['nextLockVersion'],
        );
    }

    public function activeReportIdForUpdate(int $requestId): ?int
    {
        $id = $this->db->createCommand(
            "SELECT id FROM {{%request_documents}} WHERE request_id = :request_id "
            . "AND document_type = 'report' AND deleted_at IS NULL FOR UPDATE",
            [':request_id' => $requestId],
        )->queryScalar();
        return $id === false ? null : (int) $id;
    }

    public function persistDeletion(
        DeleteReportCommand $command,
        ReportLifecycleSnapshot $snapshot,
        int $documentId,
    ): DeleteReportResult {
        $now = Clock::now();
        $this->softDeleteReport($documentId, $command->actorId, $now);
        $nextLockVersion = $command->expectedLockVersion + 1;
        $updated = $this->db->createCommand()->update('{{%requests}}', [
            'status' => RequestStatus::InProgress->value,
            'lock_version' => $nextLockVersion,
            'updated_at' => $now,
        ], ['id' => $command->requestId, 'lock_version' => $command->expectedLockVersion])->execute();
        if ($updated !== 1) {
            throw new ConcurrentRequestModification();
        }
        $this->recordDeletionTransition($command, $snapshot->status, $now);
        $this->recordSuccessfulDeletion($command, $documentId, $now);

        return new DeleteReportResult(RequestStatus::InProgress, $nextLockVersion);
    }

    public function recordRejectedUpload(int $requestId, int $actorId, string $ruleId): void
    {
        $this->recordRejected($requestId, $actorId, $ruleId, 'request.report_upload_rejected');
    }

    public function recordRejectedDeletion(int $requestId, int $actorId, string $ruleId): void
    {
        $this->recordRejected($requestId, $actorId, $ruleId, 'request.report_deletion_rejected');
    }

    /** @return array{id: int, wasDeleted: bool} */
    private function findOrCreateReport(int $requestId, int $actorId): array
    {
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

    private function nextReportVersion(int $documentId): int
    {
        return (int) $this->db->createCommand(
            'SELECT COALESCE(MAX(version), 0) + 1 FROM {{%request_document_versions}} '
            . 'WHERE document_id = :document_id',
            [':document_id' => $documentId],
        )->queryScalar();
    }

    private function insertReportVersion(
        UploadReportCommand $command,
        int $documentId,
        int $version,
        string $storageKey,
        string $sha256,
        string $now,
    ): int {
        $this->db->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $documentId,
            'version' => $version,
            'storage_key' => $storageKey,
            'original_name' => $command->originalName,
            'mime_type' => $command->mimeType,
            'size_bytes' => $command->size,
            'sha256' => $sha256,
            'uploaded_by' => $command->actorId,
            'created_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    /** @return array{targetStatus: RequestStatus, nextLockVersion: int, isFirstOrRevived: bool} */
    private function persistUploadWorkflow(
        UploadReportCommand $command,
        ReportLifecycleSnapshot $snapshot,
        bool $wasDeleted,
        int $version,
        int $versionId,
        string $now,
    ): array {
        $isFirstOrRevived = $version === 1 || $wasDeleted;
        $statusChanges = $snapshot->status === RequestStatus::InProgress
            || ($wasDeleted && $snapshot->status !== RequestStatus::OpinionPreparation);
        $requestChanges = $isFirstOrRevived || $statusChanges;
        $nextLockVersion = $snapshot->lockVersion + ($requestChanges ? 1 : 0);
        $targetStatus = $statusChanges ? RequestStatus::OpinionPreparation : $snapshot->status;
        if ($requestChanges) {
            $this->updateRequestAfterUpload($command->requestId, $snapshot, $targetStatus, $nextLockVersion, $now);
        }
        if ($statusChanges) {
            $this->recordUploadTransition($command, $snapshot->status, $wasDeleted, $versionId, $now);
        }
        return compact('targetStatus', 'nextLockVersion', 'isFirstOrRevived');
    }

    private function updateRequestAfterUpload(
        int $requestId,
        ReportLifecycleSnapshot $snapshot,
        RequestStatus $targetStatus,
        int $nextLockVersion,
        string $now,
    ): void {
        $updated = $this->db->createCommand()->update('{{%requests}}', [
            'status' => $targetStatus->value,
            'lock_version' => $nextLockVersion,
            'updated_at' => $now,
        ], [
            'id' => $requestId,
            'status' => $snapshot->status->value,
            'lock_version' => $snapshot->lockVersion,
        ])->execute();
        if ($updated !== 1) {
            throw new \RuntimeException('Concurrent report upload detected.');
        }
    }

    private function recordUploadTransition(
        UploadReportCommand $command,
        RequestStatus $fromStatus,
        bool $wasDeleted,
        int $versionId,
        string $now,
    ): void {
        $this->db->createCommand()->insert('{{%request_transitions}}', [
            'request_id' => $command->requestId,
            'actor_id' => $command->actorId,
            'from_status' => $fromStatus->value,
            'to_status' => RequestStatus::OpinionPreparation->value,
            'action' => 'upload_report',
            'rule_id' => $wasDeleted ? 'DOC-012' : 'DOC-002',
            'document_version_id' => $versionId,
            'created_at' => $now,
        ])->execute();
    }

    private function enqueueUploadNotifications(int $requestId, int $versionId): void
    {
        $outbox = new NotificationOutbox($this->db);
        foreach ($this->activeExperts() as $expert) {
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

    /** @return list<array{email: string, name: string}> */
    private function activeExperts(): array
    {
        return $this->db->createCommand(
            'SELECT DISTINCT TRIM(u.email) AS email, u.display_name AS name FROM {{%users}} u '
            . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id '
            . 'JOIN {{%roles}} r ON r.id = ur.role_id '
            . "WHERE u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != '' AND r.code = 'expert'",
        )->queryAll();
    }

    private function recordSuccessfulUpload(
        UploadReportCommand $command,
        int $documentId,
        int $versionId,
        int $version,
        bool $wasDeleted,
        bool $isFirstOrRevived,
        string $now,
    ): void {
        $ruleId = $isFirstOrRevived ? ($wasDeleted ? 'DOC-012' : 'DOC-002') : 'DOC-008';
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.report_uploaded',
            'entity_type' => 'request',
            'entity_id' => $command->requestId,
            'actor_id' => $command->actorId,
            'rule_id' => $ruleId,
            'payload_json' => ['document_id' => $documentId, 'version_id' => $versionId, 'version' => $version],
            'created_at' => $now,
        ])->execute();
    }

    private function softDeleteReport(int $documentId, int $actorId, string $now): void
    {
        $this->db->createCommand()->update('{{%request_document_versions}}', [
            'deleted_at' => $now,
        ], ['document_id' => $documentId, 'deleted_at' => null])->execute();
        $this->db->createCommand()->update('{{%request_documents}}', [
            'deleted_at' => $now,
            'deleted_by' => $actorId,
        ], ['id' => $documentId])->execute();
    }

    private function recordDeletionTransition(
        DeleteReportCommand $command,
        RequestStatus $fromStatus,
        string $now,
    ): void {
        if ($fromStatus === RequestStatus::InProgress) {
            return;
        }
        $this->db->createCommand()->insert('{{%request_transitions}}', [
            'request_id' => $command->requestId,
            'actor_id' => $command->actorId,
            'from_status' => $fromStatus->value,
            'to_status' => RequestStatus::InProgress->value,
            'action' => 'delete_report',
            'rule_id' => 'DOC-011',
            'reason' => $command->reason,
            'created_at' => $now,
        ])->execute();
    }

    private function recordSuccessfulDeletion(DeleteReportCommand $command, int $documentId, string $now): void
    {
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.report_deleted',
            'entity_type' => 'request',
            'entity_id' => $command->requestId,
            'actor_id' => $command->actorId,
            'rule_id' => 'DOC-011',
            'payload_json' => ['document_id' => $documentId, 'reason' => $command->reason],
            'created_at' => $now,
        ])->execute();
    }

    private function recordRejected(int $requestId, int $actorId, string $ruleId, string $eventType): void
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
            'event_type' => $eventType,
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => ['outcome' => 'rejected'],
            'created_at' => Clock::now(),
        ])->execute();
    }
}

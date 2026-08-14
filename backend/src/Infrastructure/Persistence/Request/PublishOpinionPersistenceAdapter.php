<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Request;

use App\Application\Request\Command\PublishOpinionCommand;
use App\Application\Request\Port\PublishOpinionGateway;
use App\Application\Request\PublishOpinionSnapshot;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestStatus;
use App\Infrastructure\Clock;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Notification\NotificationOutbox;
use yii\db\Connection;

final readonly class PublishOpinionPersistenceAdapter implements PublishOpinionGateway
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

    public function snapshotForUpdate(int $requestId, int $actorId): ?PublishOpinionSnapshot
    {
        $row = $this->db->createCommand(
            'SELECT r.number, r.status, r.lock_version AS lockVersion, r.product_name AS productName, '
            . 'r.manufacturer, r.supplier, actor.display_name AS expertName, actor.position AS expertPosition, '
            . 'actor.is_active AS actorIsActive, (expert.user_id = :expert_actor) AS isCurrentExpert '
            . 'FROM {{%requests}} r JOIN {{%users}} actor ON actor.id = :actor_id '
            . 'LEFT JOIN {{%request_assignments}} expert ON expert.request_id = r.id '
            . "AND expert.assignment_type = 'expert' AND expert.valid_to IS NULL "
            . 'WHERE r.id = :request_id FOR UPDATE',
            [':request_id' => $requestId, ':actor_id' => $actorId, ':expert_actor' => $actorId],
        )->queryOne();
        if ($row === false) {
            return null;
        }

        return new PublishOpinionSnapshot(
            (int) $row['number'],
            RequestStatus::from((string) $row['status']),
            (int) $row['lockVersion'],
            (string) $row['productName'],
            (string) $row['manufacturer'],
            (string) $row['supplier'],
            (string) $row['expertName'],
            (string) ($row['expertPosition'] ?: 'Эксперт'),
            (bool) $row['actorIsActive'],
            (bool) $row['isCurrentExpert'],
        );
    }

    public function nextRevision(int $requestId): int
    {
        return (int) $this->db->createCommand(
            'SELECT COALESCE(MAX(revision), 0) + 1 FROM {{%expert_opinions}} WHERE request_id = :request_id',
            [':request_id' => $requestId],
        )->queryScalar();
    }

    public function persistPublication(
        PublishOpinionCommand $command,
        PublishOpinionSnapshot $snapshot,
        int $revision,
        string $pdf,
    ): int {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'opinion-');
        $storageKey = null;
        try {
            if ($temporaryPath === false || file_put_contents($temporaryPath, $pdf, LOCK_EX) !== strlen($pdf)) {
                throw new \RuntimeException('Cannot prepare opinion PDF.');
            }
            $storageKey = $this->storage->store($temporaryPath);
            $now = Clock::now();
            $documentId = $this->findOrCreateOpinion($command->requestId, $command->actorId, $now);
            $this->db->createCommand()->insert('{{%request_document_versions}}', [
                'document_id' => $documentId,
                'version' => $revision,
                'storage_key' => $storageKey,
                'original_name' => sprintf('expert-opinion-%06d-v%d.pdf', $snapshot->number, $revision),
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($pdf),
                'sha256' => hash('sha256', $pdf),
                'uploaded_by' => $command->actorId,
                'created_at' => $now,
            ])->execute();
            $versionId = (int) $this->db->getLastInsertID();
            $this->persistBusinessRecords($command, $revision, $versionId, $now);
            $this->enqueueNotifications($command->requestId, $versionId);
            return $versionId;
        } catch (\Throwable $error) {
            if ($storageKey !== null) {
                $this->storage->delete($storageKey);
            }
            throw $error;
        } finally {
            if ($temporaryPath !== false && is_file($temporaryPath)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam generated this path
                unlink($temporaryPath);
            }
        }
    }

    public function recordRejected(int $requestId, int $actorId, string $ruleId): void
    {
        $allowed = $this->db->createCommand(
            'SELECT 1 FROM {{%requests}} r JOIN {{%users}} actor ON actor.id = :actor_id WHERE r.id = :request_id',
            [':request_id' => $requestId, ':actor_id' => $actorId],
        )->queryScalar();
        if ($allowed === false) {
            return;
        }
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.opinion_publish_rejected', 'entity_type' => 'request', 'entity_id' => $requestId,
            'actor_id' => $actorId, 'rule_id' => $ruleId,
            'payload_json' => ['outcome' => 'rejected'], 'created_at' => Clock::now(),
        ])->execute();
    }

    private function persistBusinessRecords(PublishOpinionCommand $command, int $revision, int $versionId, string $now): void
    {
        $this->db->createCommand()->insert('{{%expert_opinions}}', [
            'request_id' => $command->requestId, 'revision' => $revision, 'expert_id' => $command->actorId,
            'body' => $command->body, 'document_version_id' => $versionId, 'created_at' => $now,
        ])->execute();
        $nextLockVersion = $command->expectedLockVersion + 1;
        $updated = $this->db->createCommand()->update('{{%requests}}', [
            'status' => RequestStatus::SecurityReview->value, 'lock_version' => $nextLockVersion, 'updated_at' => $now,
        ], [
            'id' => $command->requestId, 'status' => RequestStatus::OpinionPreparation->value,
            'lock_version' => $command->expectedLockVersion,
        ])->execute();
        if ($updated !== 1) {
            throw new ConcurrentRequestModification();
        }
        $this->db->createCommand()->insert('{{%request_transitions}}', [
            'request_id' => $command->requestId, 'actor_id' => $command->actorId,
            'from_status' => RequestStatus::OpinionPreparation->value,
            'to_status' => RequestStatus::SecurityReview->value, 'action' => 'publish_opinion',
            'rule_id' => 'DOC-007', 'created_at' => $now,
        ])->execute();
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.opinion_published', 'entity_type' => 'request',
            'entity_id' => $command->requestId, 'actor_id' => $command->actorId, 'rule_id' => 'DOC-007',
            'payload_json' => ['revision' => $revision, 'document_version_id' => $versionId], 'created_at' => $now,
        ])->execute();
    }

    private function enqueueNotifications(int $requestId, int $versionId): void
    {
        $links = [['label' => 'заключение', 'documentVersionId' => $versionId]];
        $reportVersionId = $this->latestDocumentVersionId($requestId, 'report');
        if ($reportVersionId !== null) {
            $links[] = ['label' => 'отчёт', 'documentVersionId' => $reportVersionId];
        }
        $outbox = new NotificationOutbox($this->db);
        foreach ($this->activeSecurityOfficers() as $officer) {
            $outbox->enqueue(
                $requestId,
                'request.opinion_published',
                $officer['email'],
                $officer['name'],
                'Заключение поступило на контроль СБ',
                'Экспертное заключение опубликовано и ожидает проверки службы безопасности. Откройте заявку в портале.',
                $links,
            );
        }
    }

    private function findOrCreateOpinion(int $requestId, int $actorId, string $now): int
    {
        $id = $this->db->createCommand(
            "SELECT id FROM {{%request_documents}} WHERE request_id = :request_id AND document_type = 'opinion' FOR UPDATE",
            [':request_id' => $requestId],
        )->queryScalar();
        if ($id !== false) {
            return (int) $id;
        }
        $this->db->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId, 'document_type' => 'opinion', 'title' => 'Экспертное заключение',
            'created_by' => $actorId, 'created_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    /** @return list<array{email: string, name: string}> */
    private function activeSecurityOfficers(): array
    {
        return $this->db->createCommand(
            'SELECT DISTINCT TRIM(u.email) AS email, u.display_name AS name FROM {{%users}} u '
            . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id JOIN {{%roles}} r ON r.id = ur.role_id '
            . "WHERE u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != '' AND r.code = 'security_officer'",
        )->queryAll();
    }

    private function latestDocumentVersionId(int $requestId, string $documentType): ?int
    {
        $id = $this->db->createCommand(
            'SELECT v.id FROM {{%request_document_versions}} v JOIN {{%request_documents}} d ON d.id = v.document_id '
            . 'WHERE d.request_id = :request_id AND d.document_type = :document_type '
            . 'AND d.deleted_at IS NULL AND v.deleted_at IS NULL ORDER BY v.version DESC LIMIT 1',
            [':request_id' => $requestId, ':document_type' => $documentType],
        )->queryScalar();
        return $id === false ? null : (int) $id;
    }
}

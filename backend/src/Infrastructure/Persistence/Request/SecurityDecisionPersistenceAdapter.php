<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Request;

use App\Application\Request\Port\SecurityDecisionGateway;
use App\Application\Request\SecurityDecisionSnapshot;
use App\Domain\Request\CurrentAssignmentInvariantViolation;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutbox;
use yii\db\Connection;

final readonly class SecurityDecisionPersistenceAdapter implements SecurityDecisionGateway
{
    public function __construct(private Connection $db)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $transaction = $this->db->beginTransaction();
        try {
            $result = $operation();
            $transaction->commit();
            return $result;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    public function decisionSnapshotForUpdate(int $requestId, int $actorId): ?SecurityDecisionSnapshot
    {
        $row = $this->db->createCommand(
            'SELECT r.status, r.lock_version AS lockVersion, actor.is_active AS actorIsActive, '
            . "GROUP_CONCAT(DISTINCT role.code ORDER BY role.code SEPARATOR ',') AS roleCodes "
            . 'FROM {{%requests}} r JOIN {{%users}} actor ON actor.id = :actor_id '
            . 'LEFT JOIN {{%user_roles}} ur ON ur.user_id = actor.id '
            . 'LEFT JOIN {{%roles}} role ON role.id = ur.role_id '
            . 'WHERE r.id = :request_id GROUP BY r.id, actor.id FOR UPDATE',
            [':request_id' => $requestId, ':actor_id' => $actorId],
        )->queryOne();
        if ($row === false) {
            return null;
        }
        $roles = array_map(
            static fn (string $role): Role => Role::from($role),
            array_filter(explode(',', (string) $row['roleCodes'])),
        );

        return new SecurityDecisionSnapshot(
            RequestStatus::from((string) $row['status']),
            (int) $row['lockVersion'],
            (bool) $row['actorIsActive'],
            $roles,
        );
    }

    public function currentUncheckedOpinionIdForUpdate(int $requestId): ?int
    {
        $opinionId = $this->db->createCommand(
            'SELECT eo.id FROM {{%expert_opinions}} eo '
            . 'LEFT JOIN {{%security_checks}} sc ON sc.expert_opinion_id = eo.id '
            . 'WHERE eo.request_id = :request_id AND sc.id IS NULL '
            . 'ORDER BY eo.revision DESC LIMIT 1 FOR UPDATE',
            [':request_id' => $requestId],
        )->queryScalar();

        return $opinionId === false ? null : (int) $opinionId;
    }

    public function recordSecurityCheck(
        int $requestId,
        int $opinionId,
        int $actorId,
        string $decision,
        ?string $reason,
        string $decidedAt,
    ): void {
        $this->db->createCommand()->insert('{{%security_checks}}', [
            'request_id' => $requestId,
            'expert_opinion_id' => $opinionId,
            'officer_id' => $actorId,
            'decision' => $decision,
            'reason' => $reason,
            'created_at' => $decidedAt,
        ])->execute();
    }

    public function persistDecision(
        int $requestId,
        RequestStatus $targetStatus,
        int $expectedLockVersion,
        int $nextLockVersion,
        string $decidedAt,
    ): bool {
        return $this->db->createCommand()->update('{{%requests}}', [
            'status' => $targetStatus->value,
            'lock_version' => $nextLockVersion,
            'updated_at' => $decidedAt,
        ], [
            'id' => $requestId,
            'status' => RequestStatus::SecurityReview->value,
            'lock_version' => $expectedLockVersion,
        ])->execute() === 1;
    }

    public function recordDecision(
        int $requestId,
        int $actorId,
        string $decision,
        ?string $reason,
        RequestStatus $targetStatus,
        string $decidedAt,
    ): void {
        $action = $decision === 'approve' ? 'security_approve' : 'security_return';
        $ruleId = $decision === 'approve' ? 'SEC-002' : 'SEC-003';
        $this->db->createCommand()->insert('{{%request_transitions}}', [
            'request_id' => $requestId,
            'actor_id' => $actorId,
            'from_status' => RequestStatus::SecurityReview->value,
            'to_status' => $targetStatus->value,
            'action' => $action,
            'rule_id' => $ruleId,
            'reason' => $decision === 'return' ? $reason : null,
            'created_at' => $decidedAt,
        ])->execute();
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.security_decided',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => ['decision' => $decision, 'reason' => $reason],
            'created_at' => $decidedAt,
        ])->execute();
    }

    public function enqueueDecisionNotification(int $requestId, string $decision, ?string $reason): void
    {
        $outbox = new NotificationOutbox($this->db);
        if ($decision === 'approve') {
            $initiator = $this->initiatorContact($requestId);
            if ($initiator === null) {
                return;
            }
            $documentLinks = [];
            $reportVersionId = $this->latestDocumentVersionId($requestId, 'report');
            if ($reportVersionId !== null) {
                $documentLinks[] = ['label' => 'отчёт', 'documentVersionId' => $reportVersionId];
            }
            $opinionVersionId = $this->latestDocumentVersionId($requestId, 'opinion');
            if ($opinionVersionId !== null) {
                $documentLinks[] = ['label' => 'заключение', 'documentVersionId' => $opinionVersionId];
            }
            $outbox->enqueue(
                $requestId,
                'request.completed',
                $initiator['email'],
                $initiator['name'],
                'Испытания завершены',
                'Испытания по вашей заявке завершены. Служба безопасности согласовала заключение. '
                . 'Отчёт и заключение доступны в портале.',
                $documentLinks,
            );
            return;
        }

        $this->assertSingleCurrentAssignment($requestId, 'executor');
        $executor = $this->currentAssigneeContact($requestId, 'executor');
        if ($executor !== null) {
            $outbox->enqueue(
                $requestId,
                'request.returned',
                $executor['email'],
                $executor['name'],
                'Заявка возвращена на доработку',
                "Служба безопасности вернула заявку на доработку.\nПричина: {$reason}\n\n"
                . 'Загрузите исправленный отчёт в портале.',
            );
        }
    }

    public function decisionTimestamp(): string
    {
        return Clock::now();
    }

    public function recordRejectedDecision(int $requestId, int $actorId, string $ruleId): void
    {
        $allowedReferences = $this->db->createCommand(
            'SELECT 1 FROM {{%requests}} r JOIN {{%users}} actor ON actor.id = :actor_id WHERE r.id = :request_id',
            [':request_id' => $requestId, ':actor_id' => $actorId],
        )->queryScalar();
        if ($allowedReferences === false) {
            return;
        }
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.security_decision_rejected',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => ['outcome' => 'rejected'],
            'created_at' => Clock::now(),
        ])->execute();
    }

    private function assertSingleCurrentAssignment(int $requestId, string $type): void
    {
        $count = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%request_assignments}} '
            . 'WHERE request_id = :request_id AND assignment_type = :assignment_type AND valid_to IS NULL',
            [':request_id' => $requestId, ':assignment_type' => $type],
        )->queryScalar();
        if ($count > 1) {
            throw new CurrentAssignmentInvariantViolation($requestId, $type, $count);
        }
    }

    /** @return array{email: string, name: string}|null */
    private function initiatorContact(int $requestId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT TRIM(u.email) AS email, u.display_name AS name FROM {{%requests}} r '
            . 'JOIN {{%users}} u ON u.id = r.initiator_id '
            . "WHERE r.id = :request_id AND u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != ''",
            [':request_id' => $requestId],
        )->queryOne();

        return $row === false ? null : $row;
    }

    /** @return array{email: string, name: string}|null */
    private function currentAssigneeContact(int $requestId, string $type): ?array
    {
        $row = $this->db->createCommand(
            'SELECT TRIM(u.email) AS email, u.display_name AS name FROM {{%request_assignments}} a '
            . 'JOIN {{%users}} u ON u.id = a.user_id '
            . 'WHERE a.request_id = :request_id AND a.assignment_type = :assignment_type '
            . "AND a.valid_to IS NULL AND u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != ''",
            [':request_id' => $requestId, ':assignment_type' => $type],
        )->queryOne();

        return $row === false ? null : $row;
    }

    private function latestDocumentVersionId(int $requestId, string $documentType): ?int
    {
        $versionId = $this->db->createCommand(
            'SELECT v.id FROM {{%request_document_versions}} v '
            . 'JOIN {{%request_documents}} d ON d.id = v.document_id '
            . 'WHERE d.request_id = :request_id AND d.document_type = :document_type '
            . 'AND d.deleted_at IS NULL AND v.deleted_at IS NULL '
            . 'ORDER BY v.version DESC LIMIT 1',
            [':request_id' => $requestId, ':document_type' => $documentType],
        )->queryScalar();

        return $versionId === false ? null : (int) $versionId;
    }
}

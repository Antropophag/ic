<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Request;

use App\Application\Request\Command\ExpertAssignmentAction;
use App\Application\Request\ExpertAssignmentSnapshot;
use App\Application\Request\Port\ExpertAssignmentGateway;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutbox;
use yii\db\Connection;

final readonly class ExpertAssignmentPersistenceAdapter implements ExpertAssignmentGateway
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

    public function assignmentSnapshotForUpdate(int $requestId, int $actorId): ?ExpertAssignmentSnapshot
    {
        $row = $this->db->createCommand(
            'SELECT r.status, r.lock_version, '
            . '(current_expert.user_id = :actor_expert) AS is_current_expert '
            . 'FROM {{%requests}} r '
            . 'LEFT JOIN {{%request_assignments}} current_expert ON current_expert.request_id = r.id '
            . "AND current_expert.assignment_type = 'expert' AND current_expert.valid_to IS NULL "
            . 'WHERE r.id = :id FOR UPDATE',
            [':id' => $requestId, ':actor_expert' => $actorId],
        )->queryOne();

        return $row === false ? null : new ExpertAssignmentSnapshot(
            RequestStatus::from((string) $row['status']),
            (int) $row['lock_version'],
            (bool) $row['is_current_expert'],
        );
    }

    public function expertActiveState(int $expertId): ?bool
    {
        $isActive = $this->db->createCommand(
            'SELECT is_active FROM {{%users}} WHERE id = :id',
            [':id' => $expertId],
        )->queryScalar();

        return $isActive === false ? null : (bool) $isActive;
    }

    public function isActiveUser(int $userId): bool
    {
        return $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $userId],
        )->queryScalar() !== false;
    }

    public function rolesFor(int $userId): array
    {
        $codes = $this->db->createCommand(
            'SELECT r.code FROM {{%roles}} r '
            . 'JOIN {{%user_roles}} ur ON ur.role_id = r.id WHERE ur.user_id = :user_id',
            [':user_id' => $userId],
        )->queryColumn();

        return array_values(array_filter(array_map(
            static fn (string $code): ?Role => Role::tryFrom($code),
            $codes,
        )));
    }

    public function assignmentTimestamp(): string
    {
        return Clock::now();
    }

    public function persistRequestAssignmentVersion(
        int $requestId,
        int $currentLockVersion,
        int $nextLockVersion,
        string $assignedAt,
    ): bool {
        return $this->db->createCommand()->update('{{%requests}}', [
            'lock_version' => $nextLockVersion,
            'updated_at' => $assignedAt,
        ], [
            'id' => $requestId,
            'status' => RequestStatus::OpinionPreparation->value,
            'lock_version' => $currentLockVersion,
        ])->execute() === 1;
    }

    public function closeCurrentExpertAssignment(int $requestId, string $assignedAt): void
    {
        $this->db->createCommand()->update(
            '{{%request_assignments}}',
            ['valid_to' => $assignedAt],
            ['request_id' => $requestId, 'assignment_type' => 'expert', 'valid_to' => null],
        )->execute();
    }

    public function createExpertAssignment(int $requestId, int $expertId, int $actorId, string $assignedAt): int
    {
        $this->db->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'expert',
            'user_id' => $expertId,
            'assigned_by' => $actorId,
            'valid_from' => $assignedAt,
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    public function recordExpertAssigned(
        ExpertAssignmentAction $action,
        int $requestId,
        int $expertId,
        int $assignmentId,
        int $actorId,
        int $lockVersion,
        string $assignedAt,
    ): void {
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => match ($action) {
                ExpertAssignmentAction::Claim => 'request.expert_claimed',
                ExpertAssignmentAction::Reassign => 'request.expert_reassigned',
            },
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => match ($action) {
                ExpertAssignmentAction::Claim => 'WF-010',
                ExpertAssignmentAction::Reassign => 'WF-011',
            },
            'payload_json' => [
                'expert_id' => $expertId,
                'assignment_id' => $assignmentId,
                'lock_version' => $lockVersion,
            ],
            'created_at' => $assignedAt,
        ])->execute();
    }

    public function enqueueExpertReassigned(int $requestId, int $expertId): void
    {
        $contact = $this->userContact($expertId);
        if ($contact === null) {
            return;
        }
        $reportVersionId = $this->latestReportVersionId($requestId);
        (new NotificationOutbox($this->db))->enqueue(
            $requestId,
            'request.expert_reassigned',
            $contact['email'],
            $contact['name'],
            'Вам передана заявка для экспертного заключения',
            'Вам передана заявка для подготовки экспертного заключения. '
            . 'Откройте заявку в портале, чтобы подготовить заключение.',
            $reportVersionId === null
                ? []
                : [['label' => 'отчёт', 'documentVersionId' => $reportVersionId]],
        );
    }

    public function recordRejectedAssignment(int $requestId, int $expertId, int $actorId, string $ruleId): void
    {
        if (
            $this->db->createCommand(
                'SELECT 1 FROM {{%users}} WHERE id = :id',
                [':id' => $actorId],
            )->queryScalar() === false
        ) {
            return;
        }
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.expert_assignment_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => ['expert_id' => $expertId],
            'created_at' => Clock::now(),
        ])->execute();
    }

    /** @return array{email: string, name: string}|null */
    private function userContact(int $userId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT TRIM(email) AS email, display_name AS name FROM {{%users}} '
            . "WHERE id = :id AND is_active = 1 AND email IS NOT NULL AND TRIM(email) != ''",
            [':id' => $userId],
        )->queryOne();

        return $row === false ? null : $row;
    }

    private function latestReportVersionId(int $requestId): ?int
    {
        $versionId = $this->db->createCommand(
            'SELECT v.id FROM {{%request_document_versions}} v '
            . 'JOIN {{%request_documents}} d ON d.id = v.document_id '
            . "WHERE d.request_id = :request_id AND d.document_type = 'report' "
            . 'AND d.deleted_at IS NULL AND v.deleted_at IS NULL '
            . 'ORDER BY v.version DESC LIMIT 1',
            [':request_id' => $requestId],
        )->queryScalar();

        return $versionId === false ? null : (int) $versionId;
    }
}

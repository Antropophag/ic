<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Request;

use App\Application\Request\ExecutorAssignmentSnapshot;
use App\Application\Request\Port\ExecutorAssignmentGateway;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutbox;
use yii\db\Connection;

final readonly class ExecutorAssignmentPersistenceAdapter implements ExecutorAssignmentGateway
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

    public function assignmentSnapshotForUpdate(int $requestId): ?ExecutorAssignmentSnapshot
    {
        $row = $this->db->createCommand(
            'SELECT status, lock_version FROM {{%requests}} WHERE id = :id FOR UPDATE',
            [':id' => $requestId],
        )->queryOne();

        return $row === false ? null : new ExecutorAssignmentSnapshot(
            RequestStatus::from((string) $row['status']),
            (int) $row['lock_version'],
        );
    }

    public function executorActiveState(int $executorId): ?bool
    {
        $isActive = $this->db->createCommand(
            'SELECT is_active FROM {{%users}} WHERE id = :id',
            [':id' => $executorId],
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

    public function isCurrentExecutor(int $requestId, int $executorId): bool
    {
        return $this->db->createCommand(
            'SELECT 1 FROM {{%request_assignments}} WHERE request_id = :request_id '
            . "AND assignment_type = 'executor' AND user_id = :user_id AND valid_to IS NULL",
            [':request_id' => $requestId, ':user_id' => $executorId],
        )->queryScalar() !== false;
    }

    public function assignmentTimestamp(): string
    {
        return Clock::now();
    }

    public function persistRequestAssignmentVersion(
        int $requestId,
        RequestStatus $status,
        int $currentLockVersion,
        int $nextLockVersion,
        string $assignedAt,
    ): bool {
        return $this->db->createCommand()->update('{{%requests}}', [
            'lock_version' => $nextLockVersion,
            'updated_at' => $assignedAt,
        ], [
            'id' => $requestId,
            'status' => $status->value,
            'lock_version' => $currentLockVersion,
        ])->execute() === 1;
    }

    public function closeCurrentExecutorAssignment(int $requestId, string $assignedAt): void
    {
        $this->db->createCommand()->update(
            '{{%request_assignments}}',
            ['valid_to' => $assignedAt],
            ['request_id' => $requestId, 'assignment_type' => 'executor', 'valid_to' => null],
        )->execute();
    }

    public function createExecutorAssignment(
        int $requestId,
        int $executorId,
        int $actorId,
        string $assignedAt,
    ): int {
        $this->db->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'executor',
            'user_id' => $executorId,
            'assigned_by' => $actorId,
            'valid_from' => $assignedAt,
        ])->execute();

        return (int) $this->db->getLastInsertID();
    }

    public function recordExecutorAssigned(
        int $requestId,
        int $executorId,
        int $assignmentId,
        int $actorId,
        int $lockVersion,
        string $assignedAt,
    ): void {
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.executor_assigned',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => 'WF-001',
            'payload_json' => [
                'executor_id' => $executorId,
                'assignment_id' => $assignmentId,
                'lock_version' => $lockVersion,
            ],
            'created_at' => $assignedAt,
        ])->execute();
    }

    public function enqueueExecutorAssigned(
        int $requestId,
        int $executorId,
        RequestStatus $status,
    ): void {
        $contact = $this->userContact($executorId);
        if ($contact === null) {
            return;
        }
        $body = match ($status) {
            RequestStatus::InProgress =>
                'Вам переназначена заявка, по которой уже начались испытания. '
                . 'Откройте заявку в портале, чтобы продолжить работу.',
            RequestStatus::Suspended =>
                'Вам переназначена заявка с приостановленными работами. '
                . 'Откройте заявку в портале и проверьте её текущий статус.',
            default =>
                'Вам назначена заявка на проведение испытаний. '
                . 'Откройте заявку в портале, чтобы начать работу.',
        };
        (new NotificationOutbox($this->db))->enqueue(
            $requestId,
            'request.executor_assigned',
            $contact['email'],
            $contact['name'],
            'Вам назначена заявка на проведение испытаний',
            $body,
        );
    }

    public function recordRejectedAssignment(
        int $requestId,
        int $executorId,
        int $actorId,
        string $ruleId,
    ): void {
        if (
            $this->db->createCommand(
                'SELECT 1 FROM {{%users}} WHERE id = :id',
                [':id' => $actorId],
            )->queryScalar() === false
        ) {
            return;
        }
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.executor_assignment_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => ['executor_id' => $executorId],
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
}

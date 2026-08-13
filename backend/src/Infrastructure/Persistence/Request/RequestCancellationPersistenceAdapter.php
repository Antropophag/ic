<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Request;

use App\Application\Request\Port\RequestCancellationGateway;
use App\Application\Request\RequestCancellationSnapshot;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutbox;
use yii\db\Connection;

final readonly class RequestCancellationPersistenceAdapter implements RequestCancellationGateway
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

    public function cancellationSnapshotForUpdate(
        int $requestId,
        RequestAction $action,
    ): ?RequestCancellationSnapshot {
        $securityProjection = $action === RequestAction::Withdraw
            ? 'EXISTS(SELECT 1 FROM {{%security_checks}} sc WHERE sc.request_id = r.id)'
            : '0';
        $row = $this->db->createCommand(
            'SELECT r.status, r.lock_version, r.initiator_id, '
            . $securityProjection . ' AS reviewed_by_security '
            . 'FROM {{%requests}} r WHERE r.id = :id FOR UPDATE',
            [':id' => $requestId],
        )->queryOne();
        if ($row === false) {
            return null;
        }

        return new RequestCancellationSnapshot(
            RequestStatus::from((string) $row['status']),
            (int) $row['lock_version'],
            (int) $row['initiator_id'],
            (bool) $row['reviewed_by_security'],
        );
    }

    public function rolesFor(int $actorId): array
    {
        $codes = $this->db->createCommand(
            'SELECT r.code FROM {{%roles}} r JOIN {{%user_roles}} ur ON ur.role_id = r.id WHERE ur.user_id = :id',
            [':id' => $actorId],
        )->queryColumn();

        return array_values(array_filter(array_map(
            static fn (string $code): ?Role => Role::tryFrom($code),
            $codes,
        )));
    }

    public function isActiveUser(int $actorId): bool
    {
        return $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $actorId],
        )->queryScalar() !== false;
    }

    public function cancellationTimestamp(): string
    {
        return Clock::now();
    }

    public function persistCancellation(
        int $requestId,
        RequestStatus $from,
        RequestStatus $to,
        int $currentLockVersion,
        int $nextLockVersion,
        string $changedAt,
    ): bool {
        return $this->db->createCommand()->update('{{%requests}}', [
            'status' => $to->value,
            'lock_version' => $nextLockVersion,
            'updated_at' => $changedAt,
        ], [
            'id' => $requestId,
            'status' => $from->value,
            'lock_version' => $currentLockVersion,
        ])->execute() === 1;
    }

    public function recordCancellationTransition(
        int $requestId,
        int $actorId,
        RequestStatus $from,
        RequestStatus $to,
        RequestAction $action,
        string $ruleId,
        string $reason,
        string $changedAt,
    ): void {
        $this->db->createCommand()->insert('{{%request_transitions}}', [
            'request_id' => $requestId,
            'actor_id' => $actorId,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'action' => $action->value,
            'rule_id' => $ruleId,
            'reason' => $reason,
            'created_at' => $changedAt,
        ])->execute();
    }

    public function recordCancellationAudit(
        int $requestId,
        int $actorId,
        RequestStatus $from,
        RequestStatus $to,
        RequestAction $action,
        int $lockVersion,
        string $ruleId,
        string $reason,
        string $changedAt,
    ): void {
        $eventType = $action === RequestAction::Reject ? 'request.rejected' : 'request.withdrawn';
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => $eventType,
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [
                'from_status' => $from->value,
                'to_status' => $to->value,
                'lock_version' => $lockVersion,
                'reason' => $reason,
            ],
            'created_at' => $changedAt,
        ])->execute();
    }

    public function enqueueCancellationNotifications(int $requestId, RequestAction $action): void
    {
        $recipients = $action === RequestAction::Reject
            ? $this->initiatorRecipients($requestId)
            : $this->withdrawalRecipients($requestId);
        $outbox = new NotificationOutbox($this->db);
        foreach ($recipients as $recipient) {
            $outbox->enqueue(
                $requestId,
                $action === RequestAction::Reject ? 'request.rejected' : 'request.withdrawn',
                $recipient['email'],
                $recipient['name'],
                $action === RequestAction::Reject ? 'В проведении испытаний отказано' : 'Заявка отозвана инициатором',
                $action === RequestAction::Reject
                    ? 'В проведении испытаний по вашей заявке отказано. Подробности доступны в заявке на портале.'
                    : 'Инициатор отозвал заявку.',
            );
        }
    }

    public function recordRejectedCancellation(
        int $requestId,
        int $actorId,
        RequestAction $action,
        string $ruleId,
    ): void {
        if ($this->db->createCommand('SELECT 1 FROM {{%users}} WHERE id = :id', [':id' => $actorId])->queryScalar() === false) {
            return;
        }
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => $action === RequestAction::Reject ? 'request.reject_denied' : 'request.withdraw_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [],
            'created_at' => Clock::now(),
        ])->execute();
    }

    /** @return array<string, array{email: string, name: string}> */
    private function initiatorRecipients(int $requestId): array
    {
        $row = $this->db->createCommand(
            'SELECT TRIM(u.email) AS email, u.display_name AS name FROM {{%requests}} r '
            . 'JOIN {{%users}} u ON u.id = r.initiator_id WHERE r.id = :id '
            . "AND u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != ''",
            [':id' => $requestId],
        )->queryOne();

        return $row === false ? [] : [$row['email'] => $row];
    }

    /** @return array<string, array{email: string, name: string}> */
    private function withdrawalRecipients(int $requestId): array
    {
        $recipients = [];
        $executor = $this->db->createCommand(
            'SELECT TRIM(u.email) AS email, u.display_name AS name FROM {{%request_assignments}} a '
            . 'JOIN {{%users}} u ON u.id = a.user_id WHERE a.request_id = :id '
            . "AND a.assignment_type = 'executor' AND a.valid_to IS NULL AND u.is_active = 1 "
            . "AND u.email IS NOT NULL AND TRIM(u.email) != ''",
            [':id' => $requestId],
        )->queryOne();
        if ($executor !== false) {
            $recipients[$executor['email']] = $executor;
        }
        $managers = $this->db->createCommand(
            'SELECT DISTINCT TRIM(u.email) AS email, u.display_name AS name FROM {{%users}} u '
            . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id JOIN {{%roles}} r ON r.id = ur.role_id '
            . "WHERE u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != '' "
            . "AND r.code IN ('ic_manager', 'laboratory_manager')",
        )->queryAll();
        foreach ($managers as $manager) {
            $recipients[$manager['email']] ??= $manager;
        }

        return $recipients;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Request;

use App\Application\Request\Port\RequestLifecycleGateway;
use App\Application\Request\RequestLifecycleSnapshot;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use App\Infrastructure\Clock;
use yii\db\Connection;

final readonly class RequestLifecyclePersistenceAdapter implements RequestLifecycleGateway
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

    public function lifecycleSnapshotForUpdate(int $requestId): ?RequestLifecycleSnapshot
    {
        $request = $this->db->createCommand(
            'SELECT status, lock_version FROM {{%requests}} WHERE id = :id FOR UPDATE',
            [':id' => $requestId],
        )->queryOne();
        if ($request === false) {
            return null;
        }

        return new RequestLifecycleSnapshot(
            RequestStatus::from((string) $request['status']),
            (int) $request['lock_version'],
        );
    }

    public function rolesFor(int $actorId): array
    {
        $codes = $this->db->createCommand(
            'SELECT r.code FROM {{%roles}} r '
            . 'JOIN {{%user_roles}} ur ON ur.role_id = r.id WHERE ur.user_id = :user_id',
            [':user_id' => $actorId],
        )->queryColumn();

        return array_values(array_filter(array_map(
            static fn (string $code): ?Role => Role::tryFrom($code),
            $codes,
        )));
    }

    public function isCurrentExecutor(int $requestId, int $actorId): bool
    {
        return $this->db->createCommand(
            'SELECT 1 FROM {{%request_assignments}} WHERE request_id = :request_id '
            . "AND assignment_type = 'executor' AND user_id = :user_id AND valid_to IS NULL",
            [':request_id' => $requestId, ':user_id' => $actorId],
        )->queryScalar() !== false;
    }

    public function isActiveUser(int $actorId): bool
    {
        return $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $actorId],
        )->queryScalar() !== false;
    }

    public function lifecycleTimestamp(): string
    {
        return Clock::now();
    }

    public function persistLifecycleTransition(
        int $requestId,
        RequestStatus $from,
        RequestStatus $to,
        int $currentLockVersion,
        int $nextLockVersion,
        string $changedAt,
    ): bool {
        return $this->db->createCommand()->update(
            '{{%requests}}',
            [
                'status' => $to->value,
                'lock_version' => $nextLockVersion,
                'updated_at' => $changedAt,
            ],
            [
                'id' => $requestId,
                'status' => $from->value,
                'lock_version' => $currentLockVersion,
            ],
        )->execute() === 1;
    }

    public function recordLifecycleTransition(
        int $requestId,
        int $actorId,
        RequestStatus $from,
        RequestStatus $to,
        RequestAction $action,
        string $ruleId,
        ?string $reason,
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

    public function recordLifecycleAudit(
        int $requestId,
        int $actorId,
        RequestStatus $from,
        RequestStatus $to,
        RequestAction $action,
        int $lockVersion,
        string $ruleId,
        ?string $reason,
        string $changedAt,
    ): void {
        $eventType = match ($action) {
            RequestAction::Start => 'request.started',
            RequestAction::Suspend => 'request.suspended',
            RequestAction::Resume => 'request.resumed',
            default => throw new \LogicException("Unsupported lifecycle action {$action->value}"),
        };
        $payload = [
            'from_status' => $from->value,
            'to_status' => $to->value,
            'lock_version' => $lockVersion,
        ];
        if ($action !== RequestAction::Start) {
            $payload['reason'] = $reason;
        }

        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => $eventType,
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => $payload,
            'created_at' => $changedAt,
        ])->execute();
    }

    public function recordRejectedLifecycle(
        int $requestId,
        int $actorId,
        RequestAction $action,
        string $ruleId,
    ): void {
        $participantsExist = $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%users}} u JOIN {{%requests}} r ON r.id = :request_id '
            . 'WHERE u.id = :actor_id',
            [':request_id' => $requestId, ':actor_id' => $actorId],
        )->queryScalar();
        if ((int) $participantsExist !== 1) {
            return;
        }

        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => $action === RequestAction::Start
                ? 'request.start_denied'
                : 'request.suspend_resume_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [],
            'created_at' => Clock::now(),
        ])->execute();
    }
}

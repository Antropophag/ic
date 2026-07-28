<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\AssignmentPolicy;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\RequestWorkflow;
use App\Domain\Request\Role;
use App\Domain\Request\StartRequestPolicy;
use yii\db\Connection;

final class RequestRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed> */
    public function create(CreateRequestInput $input, int $initiatorId): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            // Отдельная строка-счётчик блокируется MariaDB и исключает выдачу
            // одинакового номера двумя параллельными запросами (REQ-002).
            $this->db->createCommand(
                'UPDATE {{%request_number_sequence}} '
                . 'SET value = LAST_INSERT_ID(value + 1) WHERE id = 1'
            )->execute();
            $number = (int) $this->db->createCommand('SELECT LAST_INSERT_ID()')->queryScalar();
            $now = gmdate('Y-m-d H:i:s.u');
            $this->db->createCommand()->insert('{{%requests}}', [
                'number' => $number,
                'initiator_id' => $initiatorId,
                'status' => RequestStatus::Registered->value,
                'product_name' => $input->productName,
                'manufacturer' => $input->manufacturer,
                'supplier' => $input->supplier,
                'sample_quantity' => $input->sampleQuantity,
                'test_method' => $input->testMethod,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
            $id = (int) $this->db->getLastInsertID();
            $this->db->createCommand()->insert('{{%request_transitions}}', [
                'request_id' => $id,
                'actor_id' => $initiatorId,
                'from_status' => null,
                'to_status' => RequestStatus::Registered->value,
                'action' => 'create',
                'rule_id' => 'REQ-007',
                'created_at' => $now,
            ])->execute();
            $transaction->commit();

            return $this->findOne($id);
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string, mixed>> */
    public function findLatest(int $limit = 50): array
    {
        return $this->db->createCommand(
            'SELECT r.id, r.number, r.status, r.product_name, r.manufacturer, '
            . 'r.supplier, r.sample_quantity, r.test_method, r.lock_version AS lockVersion, r.created_at, '
            . 'u.display_name AS initiator_name, u.department '
            . 'FROM {{%requests}} r JOIN {{%users}} u ON u.id = r.initiator_id '
            . 'ORDER BY r.number DESC LIMIT :limit',
            [':limit' => $limit],
        )->queryAll();
    }

    /** @return array<string, mixed> */
    public function assignExecutor(int $requestId, int $executorId, int $actorId): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $requestExists = $this->db->createCommand(
                'SELECT id FROM {{%requests}} WHERE id = :id FOR UPDATE',
                [':id' => $requestId],
            )->queryScalar();
            if ($requestExists === false) {
                throw new AssignmentTargetNotFound('Request not found');
            }

            $executor = $this->db->createCommand(
                'SELECT id, is_active FROM {{%users}} WHERE id = :id',
                [':id' => $executorId],
            )->queryOne();
            if ($executor === false) {
                throw new AssignmentTargetNotFound('Executor not found');
            }

            (new AssignmentPolicy())->assertCanAssign(
                $this->rolesFor($actorId),
                (bool) $executor['is_active'],
                $this->rolesFor($executorId),
            );

            $now = gmdate('Y-m-d H:i:s.u');
            $this->db->createCommand()->update(
                '{{%request_assignments}}',
                ['valid_to' => $now],
                ['request_id' => $requestId, 'assignment_type' => 'executor', 'valid_to' => null],
            )->execute();
            $this->db->createCommand()->insert('{{%request_assignments}}', [
                'request_id' => $requestId,
                'assignment_type' => 'executor',
                'user_id' => $executorId,
                'assigned_by' => $actorId,
                'valid_from' => $now,
            ])->execute();
            $assignmentId = (int) $this->db->getLastInsertID();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.executor_assigned',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => 'WF-001',
                'payload_json' => json_encode(
                    ['executor_id' => $executorId, 'assignment_id' => $assignmentId],
                    JSON_THROW_ON_ERROR,
                ),
                'created_at' => $now,
            ])->execute();
            $transaction->commit();

            return [
                'id' => $assignmentId,
                'requestId' => $requestId,
                'executorId' => $executorId,
                'assignedBy' => $actorId,
                'assignedAt' => $now,
            ];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    public function recordRejectedAssignment(
        int $requestId,
        int $executorId,
        int $actorId,
        string $ruleId,
    ): void {
        $actorExists = $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id',
            [':id' => $actorId],
        )->queryScalar();
        if ($actorExists === false) {
            return;
        }

        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.executor_assignment_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => json_encode(['executor_id' => $executorId], JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ])->execute();
    }

    /** @return array{requestId: int, status: string, lockVersion: int, startedAt: string} */
    public function startRequest(int $requestId, int $expectedLockVersion, int $actorId): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $request = $this->db->createCommand(
                'SELECT status, lock_version FROM {{%requests}} WHERE id = :id FOR UPDATE',
                [':id' => $requestId],
            )->queryOne();
            if ($request === false) {
                throw new RequestNotFound('Request not found');
            }

            $currentLockVersion = (int) $request['lock_version'];
            if ($currentLockVersion !== $expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }

            $roles = $this->rolesFor($actorId);
            (new StartRequestPolicy())->assertCanStart(
                $roles,
                $this->isCurrentExecutor($requestId, $actorId),
                $this->isActiveUser($actorId),
            );

            $currentStatus = RequestStatus::from((string) $request['status']);
            $targetStatus = (new RequestWorkflow())->transition(
                $currentStatus,
                RequestAction::Start,
                $roles,
            );
            $nextLockVersion = $currentLockVersion + 1;
            $now = gmdate('Y-m-d H:i:s.u');
            $updated = $this->db->createCommand()->update(
                '{{%requests}}',
                [
                    'status' => $targetStatus->value,
                    'lock_version' => $nextLockVersion,
                    'updated_at' => $now,
                ],
                [
                    'id' => $requestId,
                    'status' => $currentStatus->value,
                    'lock_version' => $currentLockVersion,
                ],
            )->execute();
            if ($updated !== 1) {
                throw new ConcurrentRequestModification();
            }

            $this->db->createCommand()->insert('{{%request_transitions}}', [
                'request_id' => $requestId,
                'actor_id' => $actorId,
                'from_status' => $currentStatus->value,
                'to_status' => $targetStatus->value,
                'action' => RequestAction::Start->value,
                'rule_id' => 'WF-004',
                'created_at' => $now,
            ])->execute();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.started',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => 'WF-004',
                'payload_json' => json_encode([
                    'from_status' => $currentStatus->value,
                    'to_status' => $targetStatus->value,
                    'lock_version' => $nextLockVersion,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ])->execute();
            $transaction->commit();

            return [
                'requestId' => $requestId,
                'status' => $targetStatus->value,
                'lockVersion' => $nextLockVersion,
                'startedAt' => $now,
            ];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    public function recordRejectedStart(int $requestId, int $actorId, string $ruleId): void
    {
        $participantsExist = $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%users}} u JOIN {{%requests}} r ON r.id = :request_id '
            . 'WHERE u.id = :actor_id',
            [':request_id' => $requestId, ':actor_id' => $actorId],
        )->queryScalar();
        if ((int) $participantsExist !== 1) {
            return;
        }

        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.start_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ])->execute();
    }

    /** @return list<Role> */
    private function rolesFor(int $userId): array
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

    private function isCurrentExecutor(int $requestId, int $userId): bool
    {
        return $this->db->createCommand(
            'SELECT 1 FROM {{%request_assignments}} WHERE request_id = :request_id '
            . "AND assignment_type = 'executor' AND user_id = :user_id AND valid_to IS NULL",
            [':request_id' => $requestId, ':user_id' => $userId],
        )->queryScalar() !== false;
    }

    private function isActiveUser(int $userId): bool
    {
        return $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $userId],
        )->queryScalar() !== false;
    }

    /** @return array<string, mixed> */
    private function findOne(int $id): array
    {
        return $this->db->createCommand(
            'SELECT * FROM {{%requests}} WHERE id = :id',
            [':id' => $id],
        )->queryOne();
    }
}

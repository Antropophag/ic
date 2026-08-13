<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\CommentPolicy;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\ExpertAssignmentPolicy;
use App\Domain\Request\RequestCreationPolicy;
use App\Domain\Request\RequestDepartmentMissing;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\RequestWorkflow;
use App\Domain\Request\Role;
use App\Domain\Request\SecurityDecisionPolicy;
use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutbox;
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
            (new RequestCreationPolicy())->assertCanCreate(
                $this->rolesFor($initiatorId),
                $this->isActiveUser($initiatorId),
            );
            $department = $this->db->createCommand(
                'SELECT NULLIF(TRIM(department), \'\') FROM {{%users}} WHERE id = :id FOR UPDATE',
                [':id' => $initiatorId],
            )->queryScalar();
            if ($department === false || $department === null) {
                throw new RequestDepartmentMissing('В профиле пользователя не указано подразделение. Обратитесь к администратору.');
            }

            // Отдельная строка-счётчик блокируется MariaDB и исключает выдачу
            // одинакового номера двумя параллельными запросами (REQ-002).
            $this->db->createCommand(
                'UPDATE {{%request_number_sequence}} '
                . 'SET value = LAST_INSERT_ID(value + 1) WHERE id = 1'
            )->execute();
            $number = (int) $this->db->createCommand('SELECT LAST_INSERT_ID()')->queryScalar();
            $now = Clock::now();
            $this->db->createCommand()->insert('{{%requests}}', [
                'number' => $number,
                'initiator_id' => $initiatorId,
                'department_name' => (string) $department,
                'department_source' => 'current_profile',
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
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.created',
                'entity_type' => 'request',
                'entity_id' => $id,
                'actor_id' => $initiatorId,
                'rule_id' => 'REQ-007',
                'payload_json' => ['to_status' => RequestStatus::Registered->value],
                'created_at' => $now,
            ])->execute();
            // REQ-008: руководители ИЦ и лаборатории уведомляются о новой заявке.
            $outbox = new NotificationOutbox($this->db);
            foreach ($this->activeUsersWithRoles(['ic_manager', 'laboratory_manager']) as $manager) {
                $outbox->enqueue(
                    $id,
                    'request.created',
                    $manager['email'],
                    $manager['name'],
                    sprintf('Новая заявка №%06d зарегистрирована', $number),
                    sprintf(
                        "Зарегистрирована новая заявка №%06d на проведение испытаний.\n"
                        . "Объект испытаний: %s.\n\n"
                        . 'Откройте реестр заявок в портале, чтобы назначить исполнителя.',
                        $number,
                        $input->productName,
                    ),
                );
            }
            // REQ-009: инициатора отдельно уведомляют о приёме его же заявки —
            // без этого письма у него нет подтверждения, что регистрация
            // прошла успешно и заявка действительно попала в процесс.
            $initiatorContact = $this->userContact($initiatorId);
            if ($initiatorContact !== null) {
                $outbox->enqueue(
                    $id,
                    'request.created',
                    $initiatorContact['email'],
                    $initiatorContact['name'],
                    sprintf('Заявка №%06d зарегистрирована', $number),
                    sprintf(
                        "Ваша заявка №%06d на проведение испытаний зарегистрирована.\n"
                        . "Объект испытаний: %s.\n\n"
                        . 'Мы сообщим, когда испытательный центр назначит исполнителя.',
                        $number,
                        $input->productName,
                    ),
                );
            }
            $transaction->commit();

            return $this->findOne($id);
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    /** @return array{requestId: int, decision: string, status: string, lockVersion: int} */
    public function decideSecurity(
        int $requestId,
        int $actorId,
        string $decision,
        ?string $reason,
        int $expectedLockVersion,
    ): array {
        $transaction = $this->db->beginTransaction();
        try {
            $request = $this->db->createCommand(
                'SELECT r.status, r.lock_version AS lockVersion, actor.is_active AS actorIsActive, '
                . "GROUP_CONCAT(DISTINCT role.code ORDER BY role.code SEPARATOR ',') AS roleCodes "
                . 'FROM {{%requests}} r JOIN {{%users}} actor ON actor.id = :actor_id '
                . 'LEFT JOIN {{%user_roles}} ur ON ur.user_id = actor.id '
                . 'LEFT JOIN {{%roles}} role ON role.id = ur.role_id '
                . 'WHERE r.id = :request_id GROUP BY r.id, actor.id FOR UPDATE',
                [':request_id' => $requestId, ':actor_id' => $actorId],
            )->queryOne();
            if ($request === false) {
                throw new RequestNotFound('Request not found');
            }
            $roles = array_map(
                static fn (string $role): Role => Role::from($role),
                array_filter(explode(',', (string) $request['roleCodes'])),
            );
            $targetStatus = (new SecurityDecisionPolicy())->targetStatus(
                RequestStatus::from((string) $request['status']),
                $decision,
                $reason,
                (bool) $request['actorIsActive'],
                $roles,
            );
            if ((int) $request['lockVersion'] !== $expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }
            $opinionId = $this->db->createCommand(
                'SELECT eo.id FROM {{%expert_opinions}} eo '
                . 'LEFT JOIN {{%security_checks}} sc ON sc.expert_opinion_id = eo.id '
                . 'WHERE eo.request_id = :request_id AND sc.id IS NULL '
                . 'ORDER BY eo.revision DESC LIMIT 1 FOR UPDATE',
                [':request_id' => $requestId],
            )->queryScalar();
            if ($opinionId === false) {
                throw new \RuntimeException('Current expert opinion not found or already checked.');
            }

            $now = Clock::now();
            $this->db->createCommand()->insert('{{%security_checks}}', [
                'request_id' => $requestId,
                'expert_opinion_id' => (int) $opinionId,
                'officer_id' => $actorId,
                'decision' => $decision,
                'reason' => $decision === 'return' ? $reason : null,
                'created_at' => $now,
            ])->execute();
            $nextLockVersion = $expectedLockVersion + 1;
            $updated = $this->db->createCommand()->update('{{%requests}}', [
                'status' => $targetStatus->value,
                'lock_version' => $nextLockVersion,
                'updated_at' => $now,
            ], [
                'id' => $requestId,
                'status' => RequestStatus::SecurityReview->value,
                'lock_version' => $expectedLockVersion,
            ])->execute();
            if ($updated !== 1) {
                throw new ConcurrentRequestModification();
            }
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
                'created_at' => $now,
            ])->execute();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.security_decided',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => $ruleId,
                'payload_json' => ['decision' => $decision, 'reason' => $reason],
                'created_at' => $now,
            ])->execute();
            $outbox = new NotificationOutbox($this->db);
            if ($decision === 'approve') {
                $initiator = $this->initiatorContact($requestId);
                if ($initiator !== null) {
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
                }
            } else {
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
            $transaction->commit();

            return ['requestId' => $requestId, 'decision' => $decision, 'status' => $targetStatus->value, 'lockVersion' => $nextLockVersion];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    public function recordRejectedSecurityDecision(int $requestId, int $actorId, string $ruleId): void
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


    /** @return array<string, mixed> */
    public function addComment(int $requestId, int $actorId, string $body): array
    {
        $transaction = $this->db->beginTransaction();
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
            (new CommentPolicy())->assertCanAdd(RequestStatus::from((string) $request['status']));

            $now = Clock::now();
            $this->db->createCommand()->insert('{{%request_comments}}', [
                'request_id' => $requestId,
                'author_id' => $actorId,
                'body' => $body,
                'created_at' => $now,
            ])->execute();
            $commentId = (int) $this->db->getLastInsertID();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.comment_added',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => 'COM-003',
                'payload_json' => ['comment_id' => $commentId],
                'created_at' => $now,
            ])->execute();
            // COM-006: участники процесса уведомляются о новом комментарии,
            // кроме его автора.
            $outbox = new NotificationOutbox($this->db);
            foreach ($this->processParticipants($requestId) as $participant) {
                if ((int) $participant['id'] === $actorId) {
                    continue;
                }
                $outbox->enqueue(
                    $requestId,
                    'request.commented',
                    $participant['email'],
                    $participant['name'],
                    'Новый комментарий по заявке',
                    'В заявке появился новый комментарий. '
                    . 'Откройте заявку в портале, чтобы прочитать его.',
                );
            }
            $comment = $this->db->createCommand(
                "SELECT c.id, c.body, DATE_FORMAT(c.created_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS createdAt, "
                . 'u.display_name AS authorName '
                . 'FROM {{%request_comments}} c JOIN {{%users}} u ON u.id = c.author_id WHERE c.id = :id',
                [':id' => $commentId],
            )->queryOne();
            $transaction->commit();
            return $comment;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }


    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function claimExpert(int $requestId, int $expectedLockVersion, int $actorId): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $request = $this->db->createCommand(
                'SELECT r.status, r.lock_version AS lockVersion, '
                . '(current_expert.user_id = :actor_expert) AS isCurrentExpert '
                . 'FROM {{%requests}} r '
                . 'LEFT JOIN {{%request_assignments}} current_expert ON current_expert.request_id = r.id '
                . "AND current_expert.assignment_type = 'expert' AND current_expert.valid_to IS NULL "
                . 'WHERE r.id = :id FOR UPDATE',
                [':id' => $requestId, ':actor_expert' => $actorId],
            )->queryOne();
            if ($request === false) {
                throw new AssignmentTargetNotFound('Request not found');
            }
            if ((int) $request['lockVersion'] !== $expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }
            (new ExpertAssignmentPolicy())->assertCanClaim(
                RequestStatus::from((string) $request['status']),
                $this->isActiveUser($actorId),
                $this->rolesFor($actorId),
                (bool) $request['isCurrentExpert'],
            );

            $result = $this->performExpertAssignment(
                $requestId,
                $actorId,
                $expectedLockVersion,
                $actorId,
                'request.expert_claimed',
                'WF-010',
                false,
            );
            $transaction->commit();
            return $result;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function reassignExpert(int $requestId, int $targetExpertId, int $expectedLockVersion, int $actorId): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $request = $this->db->createCommand(
                'SELECT r.status, r.lock_version AS lockVersion, '
                . '(current_expert.user_id = :actor_expert) AS isCurrentExpert '
                . 'FROM {{%requests}} r '
                . 'LEFT JOIN {{%request_assignments}} current_expert ON current_expert.request_id = r.id '
                . "AND current_expert.assignment_type = 'expert' AND current_expert.valid_to IS NULL "
                . 'WHERE r.id = :id FOR UPDATE',
                [':id' => $requestId, ':actor_expert' => $actorId],
            )->queryOne();
            if ($request === false) {
                throw new AssignmentTargetNotFound('Request not found');
            }
            if ((int) $request['lockVersion'] !== $expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }
            $target = $this->db->createCommand(
                'SELECT id, is_active FROM {{%users}} WHERE id = :id',
                [':id' => $targetExpertId],
            )->queryOne();
            if ($target === false) {
                throw new AssignmentTargetNotFound('Expert not found');
            }
            (new ExpertAssignmentPolicy())->assertCanReassign(
                RequestStatus::from((string) $request['status']),
                $this->isActiveUser($actorId),
                $this->rolesFor($actorId),
                (bool) $request['isCurrentExpert'],
                $actorId === $targetExpertId,
                (bool) $target['is_active'],
                $this->rolesFor($targetExpertId),
            );

            $result = $this->performExpertAssignment(
                $requestId,
                $targetExpertId,
                $expectedLockVersion,
                $actorId,
                'request.expert_reassigned',
                'WF-011',
                true,
            );
            $transaction->commit();
            return $result;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    /**
     * Общая часть claimExpert()/reassignExpert(): закрывает прежнее
     * назначение, открывает новое и пишет аудит внутри уже открытой
     * транзакции вызывающего метода.
     *
     * @return array<string, mixed>
     */
    private function performExpertAssignment(
        int $requestId,
        int $targetExpertId,
        int $expectedLockVersion,
        int $actorId,
        string $eventType,
        string $ruleId,
        bool $notifyTarget,
    ): array {
        $now = Clock::now();
        $nextLockVersion = $expectedLockVersion + 1;
        $updated = $this->db->createCommand()->update(
            '{{%requests}}',
            ['lock_version' => $nextLockVersion, 'updated_at' => $now],
            ['id' => $requestId, 'status' => RequestStatus::OpinionPreparation->value, 'lock_version' => $expectedLockVersion],
        )->execute();
        if ($updated !== 1) {
            throw new ConcurrentRequestModification();
        }
        $this->db->createCommand()->update(
            '{{%request_assignments}}',
            ['valid_to' => $now],
            ['request_id' => $requestId, 'assignment_type' => 'expert', 'valid_to' => null],
        )->execute();
        $this->db->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'expert',
            'user_id' => $targetExpertId,
            'assigned_by' => $actorId,
            'valid_from' => $now,
        ])->execute();
        $assignmentId = (int) $this->db->getLastInsertID();
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => $eventType,
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => ['expert_id' => $targetExpertId, 'assignment_id' => $assignmentId, 'lock_version' => $nextLockVersion],
            'created_at' => $now,
        ])->execute();
        if ($notifyTarget) {
            $expertContact = $this->userContact($targetExpertId);
            if ($expertContact !== null) {
                $reportVersionId = $this->latestDocumentVersionId($requestId, 'report');
                (new NotificationOutbox($this->db))->enqueue(
                    $requestId,
                    $eventType,
                    $expertContact['email'],
                    $expertContact['name'],
                    'Вам передана заявка для экспертного заключения',
                    'Вам передана заявка для подготовки экспертного заключения. '
                    . 'Откройте заявку в портале, чтобы подготовить заключение.',
                    $reportVersionId === null
                        ? []
                        : [['label' => 'отчёт', 'documentVersionId' => $reportVersionId]],
                );
            }
        }
        return [
            'id' => $assignmentId,
            'requestId' => $requestId,
            'expertId' => $targetExpertId,
            'assignedBy' => $actorId,
            'assignedAt' => $now,
            'lockVersion' => $nextLockVersion,
        ];
    }

    public function recordRejectedExpertAssignment(int $requestId, int $expertId, int $actorId, string $ruleId): void
    {
        if (!$this->isActiveUser($actorId) && $this->db->createCommand('SELECT 1 FROM {{%users}} WHERE id = :id', [':id' => $actorId])->queryScalar() === false) {
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

    public function recordRejectedColor(int $requestId, int $actorId, string $ruleId): void
    {
        $actorExists = $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id',
            [':id' => $actorId],
        )->queryScalar();
        if ($actorExists === false) {
            return;
        }

        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.color_mark_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [],
            'created_at' => Clock::now(),
        ])->execute();
    }

    public function recordRejectedCreate(int $actorId, string $ruleId): void
    {
        if (!$this->isActiveUser($actorId) && $this->db->createCommand('SELECT 1 FROM {{%users}} WHERE id = :id', [':id' => $actorId])->queryScalar() === false) {
            return;
        }

        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.create_denied',
            'entity_type' => 'request_creation',
            'entity_id' => $actorId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [],
            'created_at' => Clock::now(),
        ])->execute();
    }
    /** @return list<Role> */
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

    public function isActiveUser(int $userId): bool
    {
        return $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id AND is_active = 1',
            [':id' => $userId],
        )->queryScalar() !== false;
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

    private function latestDocumentVersionId(int $requestId, string $documentType): ?int
    {
        $id = $this->db->createCommand(
            'SELECT v.id FROM {{%request_document_versions}} v '
            . 'JOIN {{%request_documents}} d ON d.id = v.document_id '
            . 'WHERE d.request_id = :request_id AND d.document_type = :document_type '
            . 'AND d.deleted_at IS NULL AND v.deleted_at IS NULL '
            . 'ORDER BY v.version DESC LIMIT 1',
            [':request_id' => $requestId, ':document_type' => $documentType],
        )->queryScalar();

        return $id === false ? null : (int) $id;
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
    private function currentAssigneeContact(int $requestId, string $assignmentType): ?array
    {
        $row = $this->db->createCommand(
            'SELECT TRIM(u.email) AS email, u.display_name AS name FROM {{%request_assignments}} a '
            . 'JOIN {{%users}} u ON u.id = a.user_id '
            . 'WHERE a.request_id = :request_id AND a.assignment_type = :assignment_type '
            . "AND a.valid_to IS NULL AND u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != ''",
            [':request_id' => $requestId, ':assignment_type' => $assignmentType],
        )->queryOne();

        return $row === false ? null : $row;
    }

    /** @return list<array{id: int, email: string, name: string}> */
    private function processParticipants(int $requestId): array
    {
        return $this->db->createCommand(
            'SELECT u.id, TRIM(u.email) AS email, u.display_name AS name FROM {{%requests}} r '
            . 'JOIN {{%users}} u ON u.id = r.initiator_id '
            . "WHERE r.id = :request_id1 AND u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != '' "
            . 'UNION '
            . 'SELECT u.id, TRIM(u.email) AS email, u.display_name AS name FROM {{%request_assignments}} a '
            . 'JOIN {{%users}} u ON u.id = a.user_id '
            . 'WHERE a.request_id = :request_id2 AND a.valid_to IS NULL '
            . "AND u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) != ''",
            [':request_id1' => $requestId, ':request_id2' => $requestId],
        )->queryAll();
    }

    /** @return array<string, mixed> */
    private function findOne(int $id): array
    {
        return $this->db->createCommand(
            'SELECT id, number, legacy_id, initiator_id, status, product_name, manufacturer, supplier, '
            . 'sample_quantity, legacy_sample_quantity_raw, test_method, revision, lock_version, color, '
            . 'department_name AS department, '
            . 'created_at, updated_at FROM {{%requests}} WHERE id = :id',
            [':id' => $id],
        )->queryOne();
    }
}

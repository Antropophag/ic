<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\CommentPolicy;
use App\Domain\Request\AssignmentPolicy;
use App\Domain\Request\ColorMarkPolicy;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\ExpertAssignmentPolicy;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestCreationPolicy;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\RequestWorkflow;
use App\Domain\Request\Role;
use App\Domain\Request\SecurityDecisionPolicy;
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
            (new RequestCreationPolicy())->assertCanCreate(
                $this->rolesFor($initiatorId),
                $this->isActiveUser($initiatorId),
            );

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

            $now = gmdate('Y-m-d H:i:s.u');
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
                'payload_json' => json_encode(['decision' => $decision, 'reason' => $reason], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ])->execute();
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
            'payload_json' => json_encode(['outcome' => 'rejected'], JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ])->execute();
    }

    /** @return list<array<string, mixed>> */
    public function findLatest(int $actorId, int $limit = 50): array
    {
        return $this->db->createCommand(
            'SELECT r.id, r.number, r.status, r.color, r.product_name, r.manufacturer, '
            . 'r.supplier, r.sample_quantity, r.test_method, r.lock_version AS lockVersion, r.created_at, '
            . 'u.display_name AS initiator_name, u.department, '
            . 'executor.id AS executor_id, executor.display_name AS executor_name, '
            . 'expert.id AS expert_id, expert.display_name AS expert_name, '
            . "(EXISTS(SELECT 1 FROM {{%users}} clu WHERE clu.id = :color_actor AND clu.is_active = 1) "
            . 'AND EXISTS(SELECT 1 FROM {{%user_roles}} clr JOIN {{%roles}} clrole ON clrole.id = clr.role_id '
            . "WHERE clr.user_id = :color_actor_role AND clrole.code IN ('ic_manager', 'laboratory_manager'))) "
            . 'AS can_set_color, '
            . "(r.status = 'registered' AND EXISTS(SELECT 1 FROM {{%users}} aau "
            . 'WHERE aau.id = :active_assign_actor AND aau.is_active = 1) '
            . 'AND EXISTS(SELECT 1 FROM {{%user_roles}} aur '
            . 'JOIN {{%roles}} ar ON ar.id = aur.role_id '
            . "WHERE aur.user_id = :assign_actor AND ar.code IN ('ic_manager', 'laboratory_manager')) "
            . ') AS can_assign_executor, '
            . "(r.status = 'opinion_preparation' AND EXISTS(SELECT 1 FROM {{%users}} eau "
            . 'WHERE eau.id = :active_expert_actor AND eau.is_active = 1) '
            . 'AND EXISTS(SELECT 1 FROM {{%user_roles}} eur JOIN {{%roles}} er ON er.id = eur.role_id '
            . "WHERE eur.user_id = :expert_actor AND er.code IN ('ic_manager', 'laboratory_manager')) "
            . ') AS can_assign_expert, '
            . "(r.status = 'registered' AND EXISTS(SELECT 1 FROM {{%users}} sau "
            . 'WHERE sau.id = :active_start_actor AND sau.is_active = 1) AND '
            . '(EXISTS(SELECT 1 FROM {{%user_roles}} sur '
            . 'JOIN {{%roles}} sr ON sr.id = sur.role_id '
            . "WHERE sur.user_id = :start_manager AND sr.code IN ('ic_manager', 'laboratory_manager')) "
            . 'OR (current_executor.user_id = :start_executor AND EXISTS(SELECT 1 '
            . 'FROM {{%user_roles}} seur JOIN {{%roles}} ser ON ser.id = seur.role_id '
            . "WHERE seur.user_id = :start_executor_role AND ser.code = 'ic_executor')))) AS can_start, "
            . "(EXISTS(SELECT 1 FROM {{%users}} cau WHERE cau.id = :active_comment_actor "
            . "AND cau.is_active = 1) AND r.status IN ('registered', 'in_progress', 'suspended', "
            . "'opinion_preparation', 'security_review')) AS can_comment, "
            . "(EXISTS(SELECT 1 FROM {{%users}} dau WHERE dau.id = :active_document_actor "
            . "AND dau.is_active = 1) AND r.status IN ('registered', 'in_progress', 'suspended', "
            . "'opinion_preparation', 'security_review')) AS can_upload_document "
            . 'FROM {{%requests}} r JOIN {{%users}} u ON u.id = r.initiator_id '
            . 'LEFT JOIN {{%request_assignments}} current_executor ON current_executor.request_id = r.id '
            . "AND current_executor.assignment_type = 'executor' AND current_executor.valid_to IS NULL "
            . 'LEFT JOIN {{%users}} executor ON executor.id = current_executor.user_id '
            . 'LEFT JOIN {{%request_assignments}} current_expert ON current_expert.request_id = r.id '
            . "AND current_expert.assignment_type = 'expert' AND current_expert.valid_to IS NULL "
            . 'LEFT JOIN {{%users}} expert ON expert.id = current_expert.user_id '
            . 'ORDER BY r.number DESC LIMIT :limit',
            [
                ':color_actor' => $actorId,
                ':color_actor_role' => $actorId,
                ':assign_actor' => $actorId,
                ':active_assign_actor' => $actorId,
                ':active_expert_actor' => $actorId,
                ':expert_actor' => $actorId,
                ':start_manager' => $actorId,
                ':active_start_actor' => $actorId,
                ':start_executor' => $actorId,
                ':start_executor_role' => $actorId,
                ':active_comment_actor' => $actorId,
                ':active_document_actor' => $actorId,
                ':limit' => $limit,
            ],
        )->queryAll();
    }

    /** @return array{item: array<string, mixed>, history: list<array<string, mixed>>, comments: list<array<string, mixed>>, commentsPage: array{hasMore: bool, nextBeforeId: int|null}, documents: list<array<string, mixed>>} */
    public function findDetails(int $requestId, int $actorId): array
    {
        $item = $this->db->createCommand(
            'SELECT r.id, r.number, r.status, r.color, r.product_name, r.manufacturer, '
            . 'r.supplier, r.sample_quantity, r.test_method, r.lock_version AS lockVersion, '
            . 'r.created_at, r.updated_at, u.display_name AS initiator_name, u.department, '
            . 'executor.id AS executor_id, executor.display_name AS executor_name, '
            . 'expert.id AS expert_id, expert.display_name AS expert_name, '
            . 'EXISTS(SELECT 1 FROM {{%user_roles}} clr JOIN {{%roles}} clrole ON clrole.id = clr.role_id '
            . "WHERE clr.user_id = :color_actor AND clrole.code IN ('ic_manager', 'laboratory_manager')) "
            . 'AS can_set_color, '
            . "(r.status = 'registered' AND EXISTS(SELECT 1 FROM {{%user_roles}} aur "
            . 'JOIN {{%roles}} ar ON ar.id = aur.role_id '
            . "WHERE aur.user_id = :assign_actor AND ar.code IN ('ic_manager', 'laboratory_manager'))) "
            . 'AS can_assign_executor, '
            . "(r.status = 'opinion_preparation' AND EXISTS(SELECT 1 FROM {{%user_roles}} eur "
            . 'JOIN {{%roles}} er ON er.id = eur.role_id '
            . "WHERE eur.user_id = :expert_actor AND er.code IN ('ic_manager', 'laboratory_manager'))) "
            . 'AS can_assign_expert, '
            . "(r.status = 'registered' AND (EXISTS(SELECT 1 FROM {{%user_roles}} sur "
            . 'JOIN {{%roles}} sr ON sr.id = sur.role_id '
            . "WHERE sur.user_id = :start_manager AND sr.code IN ('ic_manager', 'laboratory_manager')) "
            . 'OR (current_executor.user_id = :start_executor AND EXISTS(SELECT 1 '
            . 'FROM {{%user_roles}} seur JOIN {{%roles}} ser ON ser.id = seur.role_id '
            . "WHERE seur.user_id = :start_executor_role AND ser.code = 'ic_executor')))) AS can_start, "
            . "(r.status IN ('registered', 'in_progress', 'suspended', 'opinion_preparation', "
            . "'security_review')) AS can_comment, "
            . "(r.status IN ('registered', 'in_progress', 'suspended', 'opinion_preparation', "
            . "'security_review')) AS can_upload_document "
            . ", (r.status IN ('in_progress', 'opinion_preparation') AND "
            . '(current_executor.user_id = :report_actor OR EXISTS(SELECT 1 FROM {{%user_roles}} rur '
            . 'JOIN {{%roles}} rr ON rr.id = rur.role_id WHERE rur.user_id = :report_manager '
            . "AND rr.code IN ('ic_manager', 'laboratory_manager')))) AS can_upload_report "
            . ", (r.status = 'opinion_preparation' AND current_expert.user_id = :opinion_actor) "
            . 'AS can_publish_opinion '
            . ", (r.status = 'security_review' AND EXISTS(SELECT 1 FROM {{%user_roles}} security_ur "
            . 'JOIN {{%roles}} security_role ON security_role.id = security_ur.role_id '
            . "WHERE security_ur.user_id = :security_actor AND security_role.code = 'security_officer')) "
            . 'AS can_security_decide '
            . 'FROM {{%requests}} r '
            . 'JOIN {{%users}} viewer ON viewer.id = :actor_id AND viewer.is_active = 1 '
            . 'JOIN {{%users}} u ON u.id = r.initiator_id '
            . 'LEFT JOIN {{%request_assignments}} current_executor ON current_executor.request_id = r.id '
            . "AND current_executor.assignment_type = 'executor' AND current_executor.valid_to IS NULL "
            . 'LEFT JOIN {{%users}} executor ON executor.id = current_executor.user_id '
            . 'LEFT JOIN {{%request_assignments}} current_expert ON current_expert.request_id = r.id '
            . "AND current_expert.assignment_type = 'expert' AND current_expert.valid_to IS NULL "
            . 'LEFT JOIN {{%users}} expert ON expert.id = current_expert.user_id '
            . 'WHERE r.id = :request_id',
            [
                ':request_id' => $requestId,
                ':actor_id' => $actorId,
                ':color_actor' => $actorId,
                ':assign_actor' => $actorId,
                ':expert_actor' => $actorId,
                ':start_manager' => $actorId,
                ':start_executor' => $actorId,
                ':start_executor_role' => $actorId,
                ':report_actor' => $actorId,
                ':report_manager' => $actorId,
                ':opinion_actor' => $actorId,
                ':security_actor' => $actorId,
            ],
        )->queryOne();
        if ($item === false) {
            throw new RequestNotFound('Request not found');
        }

        $history = $this->db->createCommand(
            'SELECT t.id, \'transition\' AS kind, t.action, t.from_status AS fromStatus, '
            . "t.to_status AS toStatus, t.rule_id AS ruleId, t.reason, DATE_FORMAT(t.created_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS occurredAt, "
            . 'u.display_name AS actorName FROM {{%request_transitions}} t '
            . 'JOIN {{%users}} u ON u.id = t.actor_id WHERE t.request_id = :transition_request_id '
            . 'UNION ALL '
            . "SELECT a.id, 'assignment' AS kind, CASE a.event_type "
            . "WHEN 'request.executor_assigned' THEN 'assign_executor' ELSE 'assign_expert' END AS action, NULL, NULL, "
            . "a.rule_id, NULL, DATE_FORMAT(a.created_at, '%Y-%m-%dT%H:%i:%s.%fZ'), u.display_name FROM {{%audit_events}} a "
            . 'JOIN {{%users}} u ON u.id = a.actor_id '
            . "WHERE a.entity_type = 'request' AND a.entity_id = :audit_request_id "
            . "AND a.event_type IN ('request.executor_assigned', 'request.expert_assigned') "
            . 'ORDER BY occurredAt DESC, kind DESC, id DESC',
            [':transition_request_id' => $requestId, ':audit_request_id' => $requestId],
        )->queryAll();

        $commentsPage = $this->queryCommentsPage($requestId, null);

        $documents = $this->db->createCommand(
            'SELECT d.id, d.document_type AS documentType, d.title, v.id AS versionId, v.version, v.original_name AS originalName, '
            . 'v.mime_type AS mimeType, v.size_bytes AS sizeBytes, v.sha256, '
            . "DATE_FORMAT(v.created_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS createdAt, "
            . 'u.display_name AS uploadedBy FROM {{%request_documents}} d '
            . 'JOIN {{%request_document_versions}} v ON v.document_id = d.id '
            . 'JOIN {{%users}} u ON u.id = v.uploaded_by '
            . 'JOIN {{%requests}} item_request ON item_request.id = d.request_id '
            . 'LEFT JOIN {{%request_assignments}} current_report_executor '
            . 'ON current_report_executor.request_id = item_request.id '
            . "AND current_report_executor.assignment_type = 'executor' "
            . 'AND current_report_executor.valid_to IS NULL '
            . 'LEFT JOIN {{%request_assignments}} current_expert '
            . 'ON current_expert.request_id = item_request.id '
            . "AND current_expert.assignment_type = 'expert' AND current_expert.valid_to IS NULL "
            . "WHERE d.request_id = :document_request_id AND ((d.document_type NOT IN ('report', 'opinion') "
            . 'AND v.version = (SELECT MAX(attachment_version.version) FROM {{%request_document_versions}} attachment_version '
            . "WHERE attachment_version.document_id = d.id)) OR (d.document_type = 'report' AND ("
            . 'current_report_executor.user_id = :report_viewer '
            . 'OR EXISTS(SELECT 1 FROM {{%user_roles}} rvur JOIN {{%roles}} rvr ON rvr.id = rvur.role_id '
            . "WHERE rvur.user_id = :report_manager_viewer AND rvr.code IN ('ic_manager', 'laboratory_manager')) "
            . "OR (d.document_type = 'report' AND item_request.status = 'completed' "
            . 'AND v.version = (SELECT MAX(public_report_version.version) FROM {{%request_document_versions}} public_report_version '
            . 'WHERE public_report_version.document_id = d.id)))) '
            . "OR (d.document_type = 'opinion' AND (current_expert.user_id = :opinion_viewer "
            . 'OR EXISTS(SELECT 1 FROM {{%expert_opinions}} visible_opinion '
            . 'WHERE visible_opinion.document_version_id = v.id AND visible_opinion.expert_id = :opinion_author_viewer) '
            . 'OR EXISTS(SELECT 1 FROM {{%user_roles}} ovur JOIN {{%roles}} ovr ON ovr.id = ovur.role_id '
            . "WHERE ovur.user_id = :opinion_privileged_viewer AND ovr.code IN ('ic_manager', 'laboratory_manager', 'security_officer')) "
            . "OR (item_request.status = 'completed' AND v.version = (SELECT MAX(public_opinion_version.version) "
            . 'FROM {{%request_document_versions}} public_opinion_version '
            . 'WHERE public_opinion_version.document_id = d.id))))) '
            . 'ORDER BY d.created_at ASC, d.id ASC, v.version DESC',
            [
                ':document_request_id' => $requestId,
                ':report_viewer' => $actorId,
                ':report_manager_viewer' => $actorId,
                ':opinion_viewer' => $actorId,
                ':opinion_author_viewer' => $actorId,
                ':opinion_privileged_viewer' => $actorId,
            ],
        )->queryAll();

        return [
            'item' => $item,
            'history' => $history,
            'comments' => $commentsPage['items'],
            'commentsPage' => [
                'hasMore' => $commentsPage['hasMore'],
                'nextBeforeId' => $commentsPage['nextBeforeId'],
            ],
            'documents' => $documents,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, hasMore: bool, nextBeforeId: int|null} */
    public function findCommentsPage(int $requestId, int $actorId, ?int $beforeId): array
    {
        $visible = $this->db->createCommand(
            'SELECT 1 FROM {{%requests}} r JOIN {{%users}} viewer '
            . 'ON viewer.id = :actor_id AND viewer.is_active = 1 WHERE r.id = :request_id',
            [':request_id' => $requestId, ':actor_id' => $actorId],
        )->queryScalar();
        if ($visible === false) {
            throw new RequestNotFound('Request not found');
        }
        return $this->queryCommentsPage($requestId, $beforeId);
    }

    /** @return array{items: list<array<string, mixed>>, hasMore: bool, nextBeforeId: int|null} */
    private function queryCommentsPage(int $requestId, ?int $beforeId): array
    {
        $parameters = [':request_id' => $requestId, ':limit' => 51];
        $cursor = '';
        if ($beforeId !== null) {
            $cursor = 'AND c.id < :before_id ';
            $parameters[':before_id'] = $beforeId;
        }
        $rows = $this->db->createCommand(
            "SELECT c.id, c.body, DATE_FORMAT(c.created_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS createdAt, "
            . 'u.display_name AS authorName FROM {{%request_comments}} c '
            . 'JOIN {{%users}} u ON u.id = c.author_id WHERE c.request_id = :request_id '
            . $cursor . 'ORDER BY c.id DESC LIMIT :limit',
            $parameters,
        )->queryAll();
        $hasMore = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $nextBeforeId = $rows === [] ? null : (int) $rows[count($rows) - 1]['id'];
        return ['items' => array_reverse($rows), 'hasMore' => $hasMore, 'nextBeforeId' => $nextBeforeId];
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

            $now = gmdate('Y-m-d H:i:s.u');
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
                'payload_json' => json_encode(['comment_id' => $commentId], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ])->execute();
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

    /** @return list<array{id: int, displayName: string}> */
    public function findActiveExecutors(): array
    {
        return $this->db->createCommand(
            'SELECT u.id, u.display_name AS displayName FROM {{%users}} u '
            . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id '
            . 'JOIN {{%roles}} r ON r.id = ur.role_id '
            . "WHERE u.is_active = 1 AND r.code = 'ic_executor' ORDER BY u.display_name",
        )->queryAll();
    }

    /** @return list<array{id: int, displayName: string}> */
    public function findActiveExperts(): array
    {
        return $this->db->createCommand(
            'SELECT u.id, u.display_name AS displayName FROM {{%users}} u '
            . 'JOIN {{%user_roles}} ur ON ur.user_id = u.id '
            . 'JOIN {{%roles}} r ON r.id = ur.role_id '
            . "WHERE u.is_active = 1 AND r.code = 'expert' ORDER BY u.display_name",
        )->queryAll();
    }

    /** @return array<string, mixed> */
    public function assignExpert(int $requestId, int $expertId, int $expectedLockVersion, int $actorId): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $request = $this->db->createCommand(
                'SELECT status, lock_version FROM {{%requests}} WHERE id = :id FOR UPDATE',
                [':id' => $requestId],
            )->queryOne();
            if ($request === false) {
                throw new AssignmentTargetNotFound('Request not found');
            }
            if ((int) $request['lock_version'] !== $expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }
            $expert = $this->db->createCommand(
                'SELECT id, is_active FROM {{%users}} WHERE id = :id',
                [':id' => $expertId],
            )->queryOne();
            if ($expert === false) {
                throw new AssignmentTargetNotFound('Expert not found');
            }
            (new ExpertAssignmentPolicy())->assertCanAssign(
                RequestStatus::from((string) $request['status']),
                $this->rolesFor($actorId),
                $this->isActiveUser($actorId),
                $this->rolesFor($expertId),
                (bool) $expert['is_active'],
            );

            $now = gmdate('Y-m-d H:i:s.u');
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
                'user_id' => $expertId,
                'assigned_by' => $actorId,
                'valid_from' => $now,
            ])->execute();
            $assignmentId = (int) $this->db->getLastInsertID();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.expert_assigned',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => 'WF-010',
                'payload_json' => json_encode(['expert_id' => $expertId, 'assignment_id' => $assignmentId, 'lock_version' => $nextLockVersion], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ])->execute();
            $transaction->commit();
            return ['id' => $assignmentId, 'requestId' => $requestId, 'expertId' => $expertId, 'assignedBy' => $actorId, 'assignedAt' => $now, 'lockVersion' => $nextLockVersion];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
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
            'payload_json' => json_encode(['expert_id' => $expertId], JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ])->execute();
    }

    /** @return array<string, mixed> */
    public function assignExecutor(
        int $requestId,
        int $executorId,
        int $expectedLockVersion,
        int $actorId,
    ): array {
        $transaction = $this->db->beginTransaction();
        try {
            $request = $this->db->createCommand(
                'SELECT status, lock_version FROM {{%requests}} WHERE id = :id FOR UPDATE',
                [':id' => $requestId],
            )->queryOne();
            if ($request === false) {
                throw new AssignmentTargetNotFound('Request not found');
            }
            if (
                (string) $request['status'] !== RequestStatus::Registered->value
                || (int) $request['lock_version'] !== $expectedLockVersion
            ) {
                throw new ConcurrentRequestModification();
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
                $this->isActiveUser($actorId),
            );

            $now = gmdate('Y-m-d H:i:s.u');
            $nextLockVersion = $expectedLockVersion + 1;
            $this->db->createCommand()->update(
                '{{%requests}}',
                ['lock_version' => $nextLockVersion, 'updated_at' => $now],
                ['id' => $requestId],
            )->execute();
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
                    [
                        'executor_id' => $executorId,
                        'assignment_id' => $assignmentId,
                        'lock_version' => $nextLockVersion,
                    ],
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
                'lockVersion' => $nextLockVersion,
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

    /** @return array{requestId: int, color: string, lockVersion: int} */
    public function setColor(int $requestId, string $color, int $expectedLockVersion, int $actorId): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $request = $this->db->createCommand(
                'SELECT lock_version FROM {{%requests}} WHERE id = :id FOR UPDATE',
                [':id' => $requestId],
            )->queryOne();
            if ($request === false) {
                throw new RequestNotFound('Request not found');
            }
            if ((int) $request['lock_version'] !== $expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }

            (new ColorMarkPolicy())->assertCanSetColor(
                $this->rolesFor($actorId),
                $this->isActiveUser($actorId),
            );

            $now = gmdate('Y-m-d H:i:s.u');
            $nextLockVersion = $expectedLockVersion + 1;
            $this->db->createCommand()->update(
                '{{%requests}}',
                ['color' => $color, 'lock_version' => $nextLockVersion, 'updated_at' => $now],
                ['id' => $requestId],
            )->execute();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.color_marked',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => 'WF-009',
                'payload_json' => json_encode(['color' => $color], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ])->execute();
            $transaction->commit();

            return ['requestId' => $requestId, 'color' => $color, 'lockVersion' => $nextLockVersion];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
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
            'payload_json' => json_encode([], JSON_THROW_ON_ERROR),
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

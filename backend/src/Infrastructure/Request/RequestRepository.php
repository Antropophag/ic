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
use App\Domain\Request\RejectPolicy;
use App\Domain\Request\RequestCreationPolicy;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\RequestWorkflow;
use App\Domain\Request\Role;
use App\Domain\Request\SecurityDecisionPolicy;
use App\Domain\Request\StartRequestPolicy;
use App\Domain\Request\SuspendResumePolicy;
use App\Domain\Request\WithdrawPolicy;
use App\Infrastructure\Clock;
use App\Infrastructure\Document\DocumentDownloadUrl;
use App\Infrastructure\Notification\NotificationOutbox;
use yii\db\Connection;

final class RequestRepository
{
    private const LAST_COMMENT_PREVIEW_LENGTH = 500;

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
            $now = Clock::now();
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
                    sprintf('Заявка №%06d принята в работу', $number),
                    sprintf(
                        "Ваша заявка №%06d на проведение испытаний зарегистрирована.\n"
                        . "Объект испытаний: %s.\n\n"
                        . 'Вы получите следующее уведомление, когда испытательный центр назначит исполнителя.',
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
                    $links = '';
                    $reportVersionId = $this->latestDocumentVersionId($requestId, 'report');
                    if ($reportVersionId !== null) {
                        $links .= "\nСсылка на отчёт: " . DocumentDownloadUrl::build($this->issueDocumentLink($reportVersionId));
                    }
                    $opinionVersionId = $this->latestDocumentVersionId($requestId, 'opinion');
                    if ($opinionVersionId !== null) {
                        $links .= "\nСсылка на заключение: " . DocumentDownloadUrl::build($this->issueDocumentLink($opinionVersionId));
                    }
                    $outbox->enqueue(
                        $requestId,
                        'request.completed',
                        $initiator['email'],
                        $initiator['name'],
                        'Испытания завершены',
                        'Испытания по вашей заявке завершены, контроль службы безопасности пройден. '
                        . 'Отчёт и заключение доступны в портале.'
                        . $links,
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

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int, pageCount: int, counts: array{active: int, all: int, mine: int}} */
    public function findPage(
        int $actorId,
        int $page,
        int $pageSize,
        string $tab,
        ?string $status,
        string $query,
        string $sort,
    ): array {
        $where = [];
        $filterParams = [];
        if ($tab === 'active') {
            $where[] = "r.status IN ('registered', 'in_progress', 'suspended', 'opinion_preparation', 'security_review')";
        } elseif ($tab === 'mine') {
            $where[] = 'r.initiator_id = :filter_actor';
            $filterParams[':filter_actor'] = $actorId;
        }
        if ($status !== null) {
            $where[] = 'r.status = :filter_status';
            $filterParams[':filter_status'] = $status;
        }
        if ($query !== '') {
            $where[] = "(LOCATE(:filter_query, LPAD(CAST(r.number AS CHAR), 6, '0')) > 0 "
                . 'OR LOCATE(:filter_query, r.product_name) > 0 OR LOCATE(:filter_query, u.display_name) > 0 '
                . 'OR LOCATE(:filter_query, u.department) > 0 OR LOCATE(:filter_query, r.supplier) > 0 '
                . 'OR LOCATE(:filter_query, executor.display_name) > 0)';
            $filterParams[':filter_query'] = $query;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $joins = ' FROM {{%requests}} r JOIN {{%users}} u ON u.id = r.initiator_id '
            . 'LEFT JOIN {{%request_assignments}} current_executor ON current_executor.id = '
            . '(SELECT MAX(executor_assignment.id) FROM {{%request_assignments}} executor_assignment '
            . "WHERE executor_assignment.request_id = r.id AND executor_assignment.assignment_type = 'executor' "
            . 'AND executor_assignment.valid_to IS NULL) '
            . 'LEFT JOIN {{%users}} executor ON executor.id = current_executor.user_id ';
        $countJoins = $query === '' ? ' FROM {{%requests}} r' : $joins;

        $total = (int) $this->db->createCommand(
            'SELECT COUNT(DISTINCT r.id)' . $countJoins . $whereSql,
            $filterParams,
        )->queryScalar();
        $pageCount = max(1, (int) ceil($total / $pageSize));
        $safePage = min($page, $pageCount);

        $items = $this->db->createCommand(
            'SELECT r.id, r.number, r.status, r.color, r.product_name, r.manufacturer, '
            . 'r.supplier, r.sample_quantity, r.test_method, r.lock_version AS lockVersion, r.created_at, '
            . 'u.display_name AS initiator_name, u.department, '
            . 'executor.id AS executor_id, executor.display_name AS executor_name, '
            . 'expert.id AS expert_id, expert.display_name AS expert_name, '
            . '(SELECT sc.decision FROM {{%security_checks}} sc WHERE sc.request_id = r.id '
            . 'ORDER BY sc.id DESC LIMIT 1) AS security_mark, '
            . "(EXISTS(SELECT 1 FROM {{%users}} clu WHERE clu.id = :color_actor AND clu.is_active = 1) "
            . 'AND EXISTS(SELECT 1 FROM {{%user_roles}} clr JOIN {{%roles}} clrole ON clrole.id = clr.role_id '
            . "WHERE clr.user_id = :color_actor_role AND clrole.code IN ('ic_manager', 'laboratory_manager'))) "
            . 'AS can_set_color, '
            . "(r.status IN ('registered', 'in_progress', 'suspended') AND EXISTS(SELECT 1 FROM {{%users}} aau "
            . 'WHERE aau.id = :active_assign_actor AND aau.is_active = 1) '
            . 'AND EXISTS(SELECT 1 FROM {{%user_roles}} aur '
            . 'JOIN {{%roles}} ar ON ar.id = aur.role_id '
            . "WHERE aur.user_id = :assign_actor AND ar.code IN ('ic_manager', 'laboratory_manager')) "
            . ') AS can_assign_executor, '
            . "(r.status = 'opinion_preparation' AND EXISTS(SELECT 1 FROM {{%users}} eau "
            . 'WHERE eau.id = :active_expert_actor AND eau.is_active = 1) '
            . 'AND EXISTS(SELECT 1 FROM {{%user_roles}} eur JOIN {{%roles}} er ON er.id = eur.role_id '
            . "WHERE eur.user_id = :expert_actor AND er.code = 'expert') "
            . 'AND (current_expert.user_id IS NULL OR current_expert.user_id != :claim_actor_current) '
            . ') AS can_claim_expert, '
            . "(r.status = 'opinion_preparation' AND current_expert.user_id = :reassign_actor "
            . 'AND EXISTS(SELECT 1 FROM {{%users}} reau WHERE reau.id = :reassign_actor_active AND reau.is_active = 1) '
            . 'AND EXISTS(SELECT 1 FROM {{%user_roles}} reur JOIN {{%roles}} rer ON rer.id = reur.role_id '
            . "WHERE reur.user_id = :reassign_actor_role AND rer.code = 'expert') "
            . ') AS can_reassign_expert, '
            . "(r.status = 'registered' AND EXISTS(SELECT 1 FROM {{%users}} sau "
            . 'WHERE sau.id = :active_start_actor AND sau.is_active = 1) AND '
            . '(EXISTS(SELECT 1 FROM {{%user_roles}} sur '
            . 'JOIN {{%roles}} sr ON sr.id = sur.role_id '
            . "WHERE sur.user_id = :start_manager AND sr.code IN ('ic_manager', 'laboratory_manager')) "
            . 'OR (current_executor.user_id = :start_executor AND EXISTS(SELECT 1 '
            . 'FROM {{%user_roles}} seur JOIN {{%roles}} ser ON ser.id = seur.role_id '
            . "WHERE seur.user_id = :start_executor_role AND ser.code = 'ic_executor')))) AS can_start, "
            // WF-005: тот же круг актёров, что и у can_start (руководитель
            // ИЦ/лаборатории или назначенный исполнитель), но применяется к
            // паре переходов in_progress<->suspended, а не к registered.
            . "(r.status = 'in_progress' AND EXISTS(SELECT 1 FROM {{%users}} spau "
            . 'WHERE spau.id = :active_suspend_actor AND spau.is_active = 1) AND '
            . '(EXISTS(SELECT 1 FROM {{%user_roles}} spur '
            . 'JOIN {{%roles}} spr ON spr.id = spur.role_id '
            . "WHERE spur.user_id = :suspend_manager AND spr.code IN ('ic_manager', 'laboratory_manager')) "
            . 'OR (current_executor.user_id = :suspend_executor AND EXISTS(SELECT 1 '
            . 'FROM {{%user_roles}} speur JOIN {{%roles}} sper ON sper.id = speur.role_id '
            . "WHERE speur.user_id = :suspend_executor_role AND sper.code = 'ic_executor')))) AS can_suspend, "
            . "(r.status = 'suspended' AND EXISTS(SELECT 1 FROM {{%users}} rsau "
            . 'WHERE rsau.id = :active_resume_actor AND rsau.is_active = 1) AND '
            . '(EXISTS(SELECT 1 FROM {{%user_roles}} rsur '
            . 'JOIN {{%roles}} rsr ON rsr.id = rsur.role_id '
            . "WHERE rsur.user_id = :resume_manager AND rsr.code IN ('ic_manager', 'laboratory_manager')) "
            . 'OR (current_executor.user_id = :resume_executor AND EXISTS(SELECT 1 '
            . 'FROM {{%user_roles}} rseur JOIN {{%roles}} rser ON rser.id = rseur.role_id '
            . "WHERE rseur.user_id = :resume_executor_role AND rser.code = 'ic_executor')))) AS can_resume, "
            . "(EXISTS(SELECT 1 FROM {{%users}} cau WHERE cau.id = :active_comment_actor "
            . "AND cau.is_active = 1) AND r.status IN ('registered', 'in_progress', 'suspended', "
            . "'opinion_preparation', 'security_review')) AS can_comment, "
            . "(EXISTS(SELECT 1 FROM {{%users}} dau WHERE dau.id = :active_document_actor "
            . "AND dau.is_active = 1) AND r.status IN ('registered', 'in_progress', 'suspended', "
            . "'opinion_preparation', 'security_review')) AS can_upload_document, "
            . "(r.status IN ('registered', 'in_progress') AND EXISTS(SELECT 1 FROM {{%users}} rju "
            . 'WHERE rju.id = :reject_actor AND rju.is_active = 1) '
            . 'AND EXISTS(SELECT 1 FROM {{%user_roles}} rjur JOIN {{%roles}} rjr ON rjr.id = rjur.role_id '
            . "WHERE rjur.user_id = :reject_actor_role AND rjr.code IN ('ic_manager', 'laboratory_manager'))) "
            . 'AS can_reject, '
            . "(r.status IN ('registered', 'in_progress', 'suspended', 'opinion_preparation') "
            . 'AND r.initiator_id = :withdraw_actor AND EXISTS(SELECT 1 FROM {{%users}} wu '
            . "WHERE wu.id = :withdraw_actor_active AND wu.is_active = 1) "
            . 'AND NOT EXISTS(SELECT 1 FROM {{%security_checks}} wsc WHERE wsc.request_id = r.id)) '
            . 'AS can_withdraw, '
            . 'last_comment_author.display_name AS last_comment_author, '
            // Предпросмотр, а не полный текст: комментарий допускает до
            // 10000 символов (COM-001), полный текст на каждую строку
            // реестра раздувал бы список без пользы (Qodo). Многоточие —
            // иначе обрыв длинного комментария в модалке неотличим от
            // короткого, у которого текст закончился сам по себе.
            . '(CASE WHEN CHAR_LENGTH(last_comment.body) > ' . self::LAST_COMMENT_PREVIEW_LENGTH
            . ' THEN CONCAT(LEFT(last_comment.body, ' . self::LAST_COMMENT_PREVIEW_LENGTH . "), '…') "
            . 'ELSE last_comment.body END) AS last_comment_body, '
            . "DATE_FORMAT(last_comment.created_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS last_comment_created_at, "
            . '(report_version.id IS NOT NULL) AS has_report, '
            . 'report_version.id AS report_version_id, report_version.original_name AS report_original_name '
            . $joins
            . 'LEFT JOIN {{%request_assignments}} current_expert ON current_expert.id = '
            . '(SELECT MAX(expert_assignment.id) FROM {{%request_assignments}} expert_assignment '
            . "WHERE expert_assignment.request_id = r.id AND expert_assignment.assignment_type = 'expert' "
            . 'AND expert_assignment.valid_to IS NULL) '
            . 'LEFT JOIN {{%users}} expert ON expert.id = current_expert.user_id '
            . 'LEFT JOIN {{%request_comments}} last_comment ON last_comment.id = ('
            . 'SELECT c.id FROM {{%request_comments}} c WHERE c.request_id = r.id '
            . 'ORDER BY c.created_at DESC, c.id DESC LIMIT 1'
            . ') '
            . 'LEFT JOIN {{%users}} last_comment_author ON last_comment_author.id = last_comment.author_id '
            // DOC-003: до завершения заявки отчёт виден только назначенному
            // исполнителю/эксперту и руководителю ИЦ/лаборатории; после
            // завершения — всем. Условие видимости стоит прямо в ON, а не
            // отдельным EXISTS — тогда has_report/version_id/original_name
            // синхронно становятся NULL для тех, кому отчёт не виден, без
            // риска повторить проверку с ошибкой в одном из трёх мест.
            . 'LEFT JOIN {{%request_documents}} report_doc ON report_doc.request_id = r.id '
            . "AND report_doc.document_type = 'report' AND report_doc.deleted_at IS NULL AND ("
            . 'current_executor.user_id = :report_flag_executor_actor '
            . 'OR current_expert.user_id = :report_flag_expert_actor '
            . "OR EXISTS(SELECT 1 FROM {{%user_roles}} rfur JOIN {{%roles}} rfr ON rfr.id = rfur.role_id "
            . "WHERE rfur.user_id = :report_flag_manager_actor AND rfr.code IN ('ic_manager', 'laboratory_manager')) "
            . "OR r.status = 'completed') "
            . 'LEFT JOIN {{%request_document_versions}} report_version ON report_version.document_id = report_doc.id '
            . 'AND report_version.deleted_at IS NULL '
            . 'AND report_version.version = (SELECT MAX(rv2.version) FROM {{%request_document_versions}} rv2 '
            . 'WHERE rv2.document_id = report_doc.id AND rv2.deleted_at IS NULL) '
            . $whereSql
            . ' ORDER BY r.number ' . ($sort === 'asc' ? 'ASC' : 'DESC') . ' LIMIT :limit OFFSET :offset',
            array_merge([
                ':color_actor' => $actorId,
                ':color_actor_role' => $actorId,
                ':assign_actor' => $actorId,
                ':active_assign_actor' => $actorId,
                ':active_expert_actor' => $actorId,
                ':expert_actor' => $actorId,
                ':claim_actor_current' => $actorId,
                ':reassign_actor' => $actorId,
                ':reassign_actor_active' => $actorId,
                ':reassign_actor_role' => $actorId,
                ':start_manager' => $actorId,
                ':active_start_actor' => $actorId,
                ':start_executor' => $actorId,
                ':start_executor_role' => $actorId,
                ':active_suspend_actor' => $actorId,
                ':suspend_manager' => $actorId,
                ':suspend_executor' => $actorId,
                ':suspend_executor_role' => $actorId,
                ':active_resume_actor' => $actorId,
                ':resume_manager' => $actorId,
                ':resume_executor' => $actorId,
                ':resume_executor_role' => $actorId,
                ':active_comment_actor' => $actorId,
                ':active_document_actor' => $actorId,
                ':reject_actor' => $actorId,
                ':reject_actor_role' => $actorId,
                ':withdraw_actor' => $actorId,
                ':withdraw_actor_active' => $actorId,
                ':report_flag_executor_actor' => $actorId,
                ':report_flag_expert_actor' => $actorId,
                ':report_flag_manager_actor' => $actorId,
                ':limit' => $pageSize,
                ':offset' => ($safePage - 1) * $pageSize,
            ], $filterParams),
        )->queryAll();

        $counts = $this->db->createCommand(
            "SELECT SUM(r.status IN ('registered', 'in_progress', 'suspended', 'opinion_preparation', 'security_review')) AS active, "
            . 'COUNT(*) AS `all`, SUM(r.initiator_id = :counts_actor) AS mine FROM {{%requests}} r',
            [':counts_actor' => $actorId],
        )->queryOne();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $safePage,
            'pageSize' => $pageSize,
            'pageCount' => $pageCount,
            'counts' => [
                'active' => (int) ($counts['active'] ?? 0),
                'all' => (int) ($counts['all'] ?? 0),
                'mine' => (int) ($counts['mine'] ?? 0),
            ],
        ];
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
            . '(SELECT sc.decision FROM {{%security_checks}} sc WHERE sc.request_id = r.id '
            . 'ORDER BY sc.id DESC LIMIT 1) AS security_mark, '
            . 'EXISTS(SELECT 1 FROM {{%user_roles}} clr JOIN {{%roles}} clrole ON clrole.id = clr.role_id '
            . "WHERE clr.user_id = :color_actor AND clrole.code IN ('ic_manager', 'laboratory_manager')) "
            . 'AS can_set_color, '
            . "(r.status IN ('registered', 'in_progress', 'suspended') AND EXISTS(SELECT 1 FROM {{%user_roles}} aur "
            . 'JOIN {{%roles}} ar ON ar.id = aur.role_id '
            . "WHERE aur.user_id = :assign_actor AND ar.code IN ('ic_manager', 'laboratory_manager'))) "
            . 'AS can_assign_executor, '
            . "(r.status = 'opinion_preparation' AND EXISTS(SELECT 1 FROM {{%user_roles}} eur "
            . 'JOIN {{%roles}} er ON er.id = eur.role_id '
            . "WHERE eur.user_id = :expert_actor AND er.code = 'expert') "
            . 'AND (current_expert.user_id IS NULL OR current_expert.user_id != :claim_actor_current)) '
            . 'AS can_claim_expert, '
            . "(r.status = 'opinion_preparation' AND current_expert.user_id = :reassign_actor "
            . 'AND EXISTS(SELECT 1 FROM {{%user_roles}} reur JOIN {{%roles}} rer ON rer.id = reur.role_id '
            . "WHERE reur.user_id = :reassign_actor_role AND rer.code = 'expert')) "
            . 'AS can_reassign_expert, '
            . "(r.status = 'registered' AND (EXISTS(SELECT 1 FROM {{%user_roles}} sur "
            . 'JOIN {{%roles}} sr ON sr.id = sur.role_id '
            . "WHERE sur.user_id = :start_manager AND sr.code IN ('ic_manager', 'laboratory_manager')) "
            . 'OR (current_executor.user_id = :start_executor AND EXISTS(SELECT 1 '
            . 'FROM {{%user_roles}} seur JOIN {{%roles}} ser ON ser.id = seur.role_id '
            . "WHERE seur.user_id = :start_executor_role AND ser.code = 'ic_executor')))) AS can_start, "
            . "(r.status = 'in_progress' AND (EXISTS(SELECT 1 FROM {{%user_roles}} spur "
            . 'JOIN {{%roles}} spr ON spr.id = spur.role_id '
            . "WHERE spur.user_id = :suspend_manager AND spr.code IN ('ic_manager', 'laboratory_manager')) "
            . 'OR (current_executor.user_id = :suspend_executor AND EXISTS(SELECT 1 '
            . 'FROM {{%user_roles}} speur JOIN {{%roles}} sper ON sper.id = speur.role_id '
            . "WHERE speur.user_id = :suspend_executor_role AND sper.code = 'ic_executor')))) AS can_suspend, "
            . "(r.status = 'suspended' AND (EXISTS(SELECT 1 FROM {{%user_roles}} rsur "
            . 'JOIN {{%roles}} rsr ON rsr.id = rsur.role_id '
            . "WHERE rsur.user_id = :resume_manager AND rsr.code IN ('ic_manager', 'laboratory_manager')) "
            . 'OR (current_executor.user_id = :resume_executor AND EXISTS(SELECT 1 '
            . 'FROM {{%user_roles}} rseur JOIN {{%roles}} rser ON rser.id = rseur.role_id '
            . "WHERE rseur.user_id = :resume_executor_role AND rser.code = 'ic_executor')))) AS can_resume, "
            . "(r.status IN ('registered', 'in_progress', 'suspended', 'opinion_preparation', "
            . "'security_review')) AS can_comment, "
            . "(r.status IN ('registered', 'in_progress', 'suspended', 'opinion_preparation', "
            . "'security_review')) AS can_upload_document "
            . ", ((r.status IN ('in_progress', 'opinion_preparation') OR (r.status = 'completed' "
            . "AND NOT EXISTS(SELECT 1 FROM {{%request_documents}} upload_report "
            . "WHERE upload_report.request_id = r.id AND upload_report.document_type = 'report' "
            . 'AND upload_report.deleted_at IS NULL))) AND '
            . '(current_executor.user_id = :report_actor OR EXISTS(SELECT 1 FROM {{%user_roles}} rur '
            . 'JOIN {{%roles}} rr ON rr.id = rur.role_id WHERE rur.user_id = :report_manager '
            . "AND rr.code IN ('ic_manager', 'laboratory_manager')))) AS can_upload_report "
            . ", (EXISTS(SELECT 1 FROM {{%request_documents}} active_report "
            . "WHERE active_report.request_id = r.id AND active_report.document_type = 'report' "
            . 'AND active_report.deleted_at IS NULL) AND '
            . '(current_executor.user_id = :delete_report_actor OR EXISTS(SELECT 1 FROM {{%user_roles}} drur '
            . 'JOIN {{%roles}} drr ON drr.id = drur.role_id WHERE drur.user_id = :delete_report_manager '
            . "AND drr.code IN ('ic_manager', 'laboratory_manager')))) AS can_delete_report "
            . ", (r.status = 'opinion_preparation' AND current_expert.user_id = :opinion_actor) "
            . 'AS can_publish_opinion '
            . ", (r.status = 'security_review' AND EXISTS(SELECT 1 FROM {{%user_roles}} security_ur "
            . 'JOIN {{%roles}} security_role ON security_role.id = security_ur.role_id '
            . "WHERE security_ur.user_id = :security_actor AND security_role.code = 'security_officer')) "
            . 'AS can_security_decide, '
            . "(r.status IN ('registered', 'in_progress') AND EXISTS(SELECT 1 FROM {{%user_roles}} rjur "
            . 'JOIN {{%roles}} rjr ON rjr.id = rjur.role_id '
            . "WHERE rjur.user_id = :reject_actor AND rjr.code IN ('ic_manager', 'laboratory_manager'))) "
            . 'AS can_reject, '
            . "(r.status IN ('registered', 'in_progress', 'suspended', 'opinion_preparation') "
            . 'AND r.initiator_id = :withdraw_actor '
            . 'AND NOT EXISTS(SELECT 1 FROM {{%security_checks}} wsc WHERE wsc.request_id = r.id)) '
            . 'AS can_withdraw '
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
                ':claim_actor_current' => $actorId,
                ':reassign_actor' => $actorId,
                ':reassign_actor_role' => $actorId,
                ':start_manager' => $actorId,
                ':start_executor' => $actorId,
                ':start_executor_role' => $actorId,
                ':suspend_manager' => $actorId,
                ':suspend_executor' => $actorId,
                ':suspend_executor_role' => $actorId,
                ':resume_manager' => $actorId,
                ':resume_executor' => $actorId,
                ':resume_executor_role' => $actorId,
                ':report_actor' => $actorId,
                ':report_manager' => $actorId,
                ':delete_report_actor' => $actorId,
                ':delete_report_manager' => $actorId,
                ':opinion_actor' => $actorId,
                ':security_actor' => $actorId,
                ':reject_actor' => $actorId,
                ':withdraw_actor' => $actorId,
            ],
        )->queryOne();
        if ($item === false) {
            throw new RequestNotFound('Request not found');
        }

        $history = $this->db->createCommand(
            'SELECT t.id, \'transition\' AS kind, t.action, t.from_status AS fromStatus, '
            . "t.to_status AS toStatus, t.rule_id AS ruleId, t.reason, DATE_FORMAT(t.created_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS occurredAt, "
            . 'u.display_name AS actorName, NULL AS targetName, '
            . 'CASE WHEN report_document.id IS NOT NULL THEN report_version.id ELSE NULL END AS versionId, '
            . 'CASE WHEN report_document.id IS NOT NULL THEN report_version.original_name ELSE NULL END AS originalName '
            . 'FROM {{%request_transitions}} t '
            . 'JOIN {{%users}} u ON u.id = t.actor_id '
            . 'JOIN {{%requests}} history_request ON history_request.id = t.request_id '
            . 'LEFT JOIN {{%request_document_versions}} report_version ON report_version.id = t.document_version_id '
            . 'AND report_version.deleted_at IS NULL AND ('
            . 'EXISTS(SELECT 1 FROM {{%request_assignments}} report_assignment '
            . 'WHERE report_assignment.request_id = t.request_id AND report_assignment.user_id = :history_report_viewer '
            . "AND report_assignment.assignment_type IN ('executor', 'expert') AND report_assignment.valid_to IS NULL) "
            . 'OR EXISTS(SELECT 1 FROM {{%user_roles}} history_ur JOIN {{%roles}} history_role ON history_role.id = history_ur.role_id '
            . "WHERE history_ur.user_id = :history_report_privileged_viewer AND history_role.code IN ('ic_manager', 'laboratory_manager')) "
            . "OR (history_request.status = 'completed' AND report_version.version = ("
            . 'SELECT MAX(history_public_version.version) FROM {{%request_document_versions}} history_public_version '
            . 'WHERE history_public_version.document_id = report_version.document_id '
            . 'AND history_public_version.deleted_at IS NULL))) '
            . 'LEFT JOIN {{%request_documents}} report_document ON report_document.id = report_version.document_id '
            . 'AND report_document.request_id = t.request_id '
            . "AND report_document.document_type = 'report' AND report_document.deleted_at IS NULL "
            . 'WHERE t.request_id = :transition_request_id '
            . 'UNION ALL '
            . "SELECT a.id, 'assignment' AS kind, CASE a.event_type "
            . "WHEN 'request.executor_assigned' THEN 'assign_executor' "
            . "WHEN 'request.expert_claimed' THEN 'claim_expert' "
            . "WHEN 'request.expert_reassigned' THEN 'reassign_expert' "
            . "ELSE 'delete_report' END AS action, NULL, NULL, "
            . "a.rule_id, NULL, DATE_FORMAT(a.created_at, '%Y-%m-%dT%H:%i:%s.%fZ'), u.display_name, target_user.display_name, NULL, NULL "
            . 'FROM {{%audit_events}} a '
            . 'JOIN {{%users}} u ON u.id = a.actor_id '
            // assign_executor/claim_expert/reassign_expert пишут одинаковое
            // поле assignment_id в payload_json (см. RequestRepository::
            // assignExecutor()/performExpertAssignment()) — резолвим имя
            // адресата действия через саму запись назначения, а не парсим
            // executor_id/expert_id по отдельности (разные ключи на разные
            // события): проще и работает для report_deleted (NULL) тоже.
            // JSON_TYPE(...) = 'STRING' отличает записи, сделанные до
            // миграции m260730_000001 (двойное JSON-кодирование, issue про
            // payload_json) — JSON_UNQUOTE разворачивает их на один
            // уровень, чтобы имя резолвилось и без выполненного backfill.
            . 'LEFT JOIN {{%request_assignments}} target_assignment '
            . 'ON target_assignment.id = CAST(JSON_EXTRACT('
            . "CASE WHEN JSON_TYPE(a.payload_json) = 'STRING' THEN JSON_UNQUOTE(a.payload_json) ELSE a.payload_json END, "
            . "'$.assignment_id') AS UNSIGNED) "
            . 'LEFT JOIN {{%users}} target_user ON target_user.id = target_assignment.user_id '
            . "WHERE a.entity_type = 'request' AND a.entity_id = :audit_request_id "
            . "AND a.event_type IN ('request.executor_assigned', 'request.expert_claimed', "
            . "'request.expert_reassigned', 'request.report_deleted') "
            . 'ORDER BY occurredAt DESC, kind DESC, id DESC',
            [
                ':transition_request_id' => $requestId,
                ':audit_request_id' => $requestId,
                ':history_report_viewer' => $actorId,
                ':history_report_privileged_viewer' => $actorId,
            ],
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
            . "WHERE d.request_id = :document_request_id AND d.deleted_at IS NULL AND v.deleted_at IS NULL "
            . "AND ((d.document_type NOT IN ('report', 'opinion') "
            . 'AND v.version = (SELECT MAX(attachment_version.version) FROM {{%request_document_versions}} attachment_version '
            . "WHERE attachment_version.document_id = d.id)) OR (d.document_type = 'report' AND ("
            . 'current_report_executor.user_id = :report_viewer '
            . 'OR current_expert.user_id = :report_expert_viewer '
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
                ':report_expert_viewer' => $actorId,
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
                    . 'Откройте карточку заявки в портале, чтобы прочитать его.',
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
                $reportLink = $reportVersionId === null
                    ? ''
                    : "\nСсылка на отчёт: " . DocumentDownloadUrl::build($this->issueDocumentLink($reportVersionId));
                (new NotificationOutbox($this->db))->enqueue(
                    $requestId,
                    $eventType,
                    $expertContact['email'],
                    $expertContact['name'],
                    'Вам передана заявка для экспертного заключения',
                    'Вам передана заявка на подготовку экспертного заключения. '
                    . 'Откройте карточку заявки в портале, чтобы сформировать заключение.'
                    . $reportLink,
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
            $reassignableStatuses = [
                RequestStatus::Registered->value,
                RequestStatus::InProgress->value,
                RequestStatus::Suspended->value,
            ];
            if (
                !in_array((string) $request['status'], $reassignableStatuses, true)
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
                $this->isCurrentExecutor($requestId, $executorId),
            );

            $now = Clock::now();
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
                'payload_json' => [
                    'executor_id' => $executorId,
                    'assignment_id' => $assignmentId,
                    'lock_version' => $nextLockVersion,
                ],
                'created_at' => $now,
            ])->execute();
            $executorContact = $this->userContact($executorId);
            if ($executorContact !== null) {
                // WF-012: помимо первичного назначения (registered) заявку
                // можно переназначить и после того, как она уже в работе —
                // получателю в этом случае не нужно «принимать в работу»
                // то, что уже идёт, текст письма отражает реальный статус.
                $body = match ((string) $request['status']) {
                    RequestStatus::InProgress->value =>
                        'Вам переназначена заявка на проведение испытаний, она уже в работе. '
                        . 'Откройте карточку заявки в портале, чтобы продолжить выполнение.',
                    RequestStatus::Suspended->value =>
                        'Вам переназначена заявка на проведение испытаний, работы по ней приостановлены. '
                        . 'Откройте карточку заявки в портале, чтобы ознакомиться с текущим статусом.',
                    default =>
                        'Вам назначена заявка на проведение испытаний. '
                        . 'Откройте карточку заявки в портале, чтобы принять её в работу.',
                };
                (new NotificationOutbox($this->db))->enqueue(
                    $requestId,
                    'request.executor_assigned',
                    $executorContact['email'],
                    $executorContact['name'],
                    'Вам назначена заявка на проведение испытаний',
                    $body,
                );
            }
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
            'payload_json' => ['executor_id' => $executorId],
            'created_at' => Clock::now(),
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

            $now = Clock::now();
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
                'payload_json' => ['color' => $color],
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
            'payload_json' => [],
            'created_at' => Clock::now(),
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
            $now = Clock::now();
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
                'payload_json' => [
                    'from_status' => $currentStatus->value,
                    'to_status' => $targetStatus->value,
                    'lock_version' => $nextLockVersion,
                ],
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

    /** @return array{requestId: int, status: string, lockVersion: int} */
    public function suspendRequest(int $requestId, int $expectedLockVersion, int $actorId): array
    {
        return $this->transitionSuspendResume(
            $requestId,
            $expectedLockVersion,
            $actorId,
            RequestAction::Suspend,
            'request.suspended',
        );
    }

    /** @return array{requestId: int, status: string, lockVersion: int} */
    public function resumeRequest(int $requestId, int $expectedLockVersion, int $actorId): array
    {
        return $this->transitionSuspendResume(
            $requestId,
            $expectedLockVersion,
            $actorId,
            RequestAction::Resume,
            'request.resumed',
        );
    }

    /** @return array{requestId: int, status: string, lockVersion: int} */
    private function transitionSuspendResume(
        int $requestId,
        int $expectedLockVersion,
        int $actorId,
        RequestAction $action,
        string $eventType,
    ): array {
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
            $isCurrentExecutor = $this->isCurrentExecutor($requestId, $actorId);
            $isActive = $this->isActiveUser($actorId);
            $policy = new SuspendResumePolicy();
            if ($action === RequestAction::Suspend) {
                $policy->assertCanSuspend($roles, $isCurrentExecutor, $isActive);
            } else {
                $policy->assertCanResume($roles, $isCurrentExecutor, $isActive);
            }

            $currentStatus = RequestStatus::from((string) $request['status']);
            $targetStatus = (new RequestWorkflow())->transition($currentStatus, $action, $roles);
            $nextLockVersion = $currentLockVersion + 1;
            $now = Clock::now();
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
                'action' => $action->value,
                'rule_id' => 'WF-005',
                'created_at' => $now,
            ])->execute();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => $eventType,
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => 'WF-005',
                'payload_json' => [
                    'from_status' => $currentStatus->value,
                    'to_status' => $targetStatus->value,
                    'lock_version' => $nextLockVersion,
                ],
                'created_at' => $now,
            ])->execute();
            $transaction->commit();

            return [
                'requestId' => $requestId,
                'status' => $targetStatus->value,
                'lockVersion' => $nextLockVersion,
            ];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    public function recordRejectedSuspendResume(int $requestId, int $actorId, string $ruleId): void
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
            'event_type' => 'request.suspend_resume_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [],
            'created_at' => Clock::now(),
        ])->execute();
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

    /** @return array{requestId: int, status: string, lockVersion: int} */
    public function rejectRequest(int $requestId, int $expectedLockVersion, int $actorId, ?string $reason = null): array
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
            $currentStatus = RequestStatus::from((string) $request['status']);
            if (
                !in_array($currentStatus, [RequestStatus::Registered, RequestStatus::InProgress], true)
                || (int) $request['lock_version'] !== $expectedLockVersion
            ) {
                throw new ConcurrentRequestModification();
            }

            (new RejectPolicy())->assertCanReject($this->rolesFor($actorId), $this->isActiveUser($actorId));

            $now = Clock::now();
            $nextLockVersion = $expectedLockVersion + 1;
            $updated = $this->db->createCommand()->update('{{%requests}}', [
                'status' => RequestStatus::Rejected->value,
                'lock_version' => $nextLockVersion,
                'updated_at' => $now,
            ], [
                'id' => $requestId,
                'status' => $currentStatus->value,
                'lock_version' => $expectedLockVersion,
            ])->execute();
            if ($updated !== 1) {
                throw new ConcurrentRequestModification();
            }
            $this->db->createCommand()->insert('{{%request_transitions}}', [
                'request_id' => $requestId,
                'actor_id' => $actorId,
                'from_status' => $currentStatus->value,
                'to_status' => RequestStatus::Rejected->value,
                'action' => 'reject',
                'rule_id' => 'WF-006',
                'reason' => $reason,
                'created_at' => $now,
            ])->execute();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.rejected',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => 'WF-006',
                'payload_json' => [
                    'from_status' => $currentStatus->value,
                    'to_status' => RequestStatus::Rejected->value,
                    'lock_version' => $nextLockVersion,
                    'reason' => $reason,
                ],
                'created_at' => $now,
            ])->execute();
            $initiator = $this->initiatorContact($requestId);
            if ($initiator !== null) {
                (new NotificationOutbox($this->db))->enqueue(
                    $requestId,
                    'request.rejected',
                    $initiator['email'],
                    $initiator['name'],
                    'В проведении испытаний отказано',
                    'По вашей заявке принято решение об отказе в проведении испытаний. '
                    . 'Подробности — в карточке заявки в портале.',
                );
            }
            $transaction->commit();

            return ['requestId' => $requestId, 'status' => RequestStatus::Rejected->value, 'lockVersion' => $nextLockVersion];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    public function recordRejectedReject(int $requestId, int $actorId, string $ruleId): void
    {
        $actorExists = $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id',
            [':id' => $actorId],
        )->queryScalar();
        if ($actorExists === false) {
            return;
        }

        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.reject_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [],
            'created_at' => Clock::now(),
        ])->execute();
    }

    private const WITHDRAWABLE_STATUSES = [
        RequestStatus::Registered,
        RequestStatus::InProgress,
        RequestStatus::Suspended,
        RequestStatus::OpinionPreparation,
    ];

    /** @return array{requestId: int, status: string, lockVersion: int} */
    public function withdrawRequest(int $requestId, int $expectedLockVersion, int $actorId, ?string $reason = null): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $request = $this->db->createCommand(
                'SELECT r.status, r.lock_version, r.initiator_id, '
                . 'EXISTS(SELECT 1 FROM {{%security_checks}} sc WHERE sc.request_id = r.id) AS reviewed_by_security '
                . 'FROM {{%requests}} r WHERE r.id = :id FOR UPDATE',
                [':id' => $requestId],
            )->queryOne();
            if ($request === false) {
                throw new RequestNotFound('Request not found');
            }
            $currentStatus = RequestStatus::from((string) $request['status']);
            // WF-007: отзыв разрешён только до контроля СБ. Заявка, уже
            // побывавшая на контроле (в том числе возвращённая обратно в
            // работу), больше не считается "до контроля СБ" — даже если
            // текущий статус формально входит в WITHDRAWABLE_STATUSES.
            if (
                !in_array($currentStatus, self::WITHDRAWABLE_STATUSES, true)
                || (bool) $request['reviewed_by_security']
                || (int) $request['lock_version'] !== $expectedLockVersion
            ) {
                throw new ConcurrentRequestModification();
            }

            $isInitiator = (int) $request['initiator_id'] === $actorId;
            (new WithdrawPolicy())->assertCanWithdraw($isInitiator, $this->isActiveUser($actorId));

            $now = Clock::now();
            $nextLockVersion = $expectedLockVersion + 1;
            $updated = $this->db->createCommand()->update('{{%requests}}', [
                'status' => RequestStatus::Withdrawn->value,
                'lock_version' => $nextLockVersion,
                'updated_at' => $now,
            ], [
                'id' => $requestId,
                'status' => $currentStatus->value,
                'lock_version' => $expectedLockVersion,
            ])->execute();
            if ($updated !== 1) {
                throw new ConcurrentRequestModification();
            }
            $this->db->createCommand()->insert('{{%request_transitions}}', [
                'request_id' => $requestId,
                'actor_id' => $actorId,
                'from_status' => $currentStatus->value,
                'to_status' => RequestStatus::Withdrawn->value,
                'action' => 'withdraw',
                'rule_id' => 'WF-007',
                'reason' => $reason,
                'created_at' => $now,
            ])->execute();
            $this->db->createCommand()->insert('{{%audit_events}}', [
                'event_type' => 'request.withdrawn',
                'entity_type' => 'request',
                'entity_id' => $requestId,
                'actor_id' => $actorId,
                'rule_id' => 'WF-007',
                'payload_json' => [
                    'from_status' => $currentStatus->value,
                    'to_status' => RequestStatus::Withdrawn->value,
                    'lock_version' => $nextLockVersion,
                    'reason' => $reason,
                ],
                'created_at' => $now,
            ])->execute();

            // ТЗ 3.8: уведомление исполнителю и руководителям об отзыве.
            // Один и тот же человек может одновременно быть исполнителем и
            // руководителем (AUTH-005) — получатели дедуплицируются по email,
            // чтобы не отправить два одинаковых письма.
            $recipients = [];
            $executor = $this->currentAssigneeContact($requestId, 'executor');
            if ($executor !== null) {
                $recipients[$executor['email']] = $executor;
            }
            foreach ($this->activeUsersWithRoles(['ic_manager', 'laboratory_manager']) as $manager) {
                $recipients[$manager['email']] ??= $manager;
            }
            $outbox = new NotificationOutbox($this->db);
            foreach ($recipients as $recipient) {
                $outbox->enqueue(
                    $requestId,
                    'request.withdrawn',
                    $recipient['email'],
                    $recipient['name'],
                    'Заявка отозвана инициатором',
                    'Инициатор отозвал заявку.',
                );
            }

            $transaction->commit();

            return ['requestId' => $requestId, 'status' => RequestStatus::Withdrawn->value, 'lockVersion' => $nextLockVersion];
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    public function recordRejectedWithdraw(int $requestId, int $actorId, string $ruleId): void
    {
        $actorExists = $this->db->createCommand(
            'SELECT 1 FROM {{%users}} WHERE id = :id',
            [':id' => $actorId],
        )->queryScalar();
        if ($actorExists === false) {
            return;
        }

        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.withdraw_denied',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => $ruleId,
            'payload_json' => [],
            'created_at' => Clock::now(),
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
            . 'ORDER BY v.version DESC LIMIT 1',
            [':request_id' => $requestId, ':document_type' => $documentType],
        )->queryScalar();

        return $id === false ? null : (int) $id;
    }

    // ACL-003..006: письмо-уведомление содержит активную ссылку на скачивание
    // документа без входа в портал (ТЗ 4.6/4.9/4.10), в т.ч. пока обычный
    // доступ через портал ещё не открыт.
    private function issueDocumentLink(int $documentVersionId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->createCommand()->insert('{{%document_download_links}}', [
            'document_version_id' => $documentVersionId,
            'token_hash' => hash('sha256', $token),
            'created_at' => Clock::now(),
        ])->execute();

        return $token;
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
            'SELECT * FROM {{%requests}} WHERE id = :id',
            [':id' => $id],
        )->queryOne();
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

use App\Domain\Request\AttentionQueue;
use App\Domain\Request\RequestNotFound;
use yii\db\Connection;

final class RequestQuery
{
    public function isArchived(int $requestId): bool
    {
        return (int) $this->db->createCommand(
            'SELECT is_archived FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryScalar() === 1;
    }

    private const LAST_COMMENT_PREVIEW_LENGTH = 500;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function recentEvents(int $actorId): array
    {
        return $this->db->createCommand(
            "SELECT id, requestId, requestNumber, productName, type, title, authorName, "
            . "DATE_FORMAT(occurredAtRaw, '%Y-%m-%dT%H:%i:%s.%fZ') AS occurredAt FROM ("
            . 'SELECT * FROM ('
            . "SELECT CONCAT('comment-', c.id) AS id, c.request_id AS requestId, "
            . "LPAD(CAST(r.number AS CHAR), 6, '0') AS requestNumber, r.product_name AS productName, "
            . "'comment' AS type, 'Новый комментарий' AS title, u.display_name AS authorName, "
            . 'c.created_at AS occurredAtRaw, c.id AS sourceId '
            . 'FROM {{%request_comments}} c JOIN {{%requests}} r ON r.id = c.request_id '
            . 'JOIN {{%users}} u ON u.id = c.author_id WHERE c.author_id != :comment_actor '
            . 'ORDER BY c.created_at DESC, c.id DESC LIMIT 100) recent_comments '
            . 'UNION ALL SELECT * FROM ('
            . "SELECT CONCAT('transition-', t.id) AS id, t.request_id AS requestId, "
            . "LPAD(CAST(r.number AS CHAR), 6, '0') AS requestNumber, r.product_name AS productName, "
            . "'event' AS type, CASE t.action "
            . "WHEN 'start' THEN 'Заявка переведена в работу' WHEN 'suspend' THEN 'Работа приостановлена' "
            . "WHEN 'resume' THEN 'Работа возобновлена' WHEN 'upload_report' THEN 'Загружен отчёт испытаний' "
            . "WHEN 'publish_opinion' THEN 'Опубликовано экспертное заключение' "
            . "WHEN 'security_approve' THEN 'Заключение согласовано' WHEN 'security_return' THEN 'Заявка возвращена в работу' "
            . "WHEN 'reject' THEN 'В испытаниях отказано' WHEN 'withdraw' THEN 'Заявка отозвана' ELSE 'Событие в заявке' END AS title, "
            . 'u.display_name AS authorName, t.created_at AS occurredAtRaw, t.id AS sourceId '
            . 'FROM {{%request_transitions}} t JOIN {{%requests}} r ON r.id = t.request_id '
            . 'JOIN {{%users}} u ON u.id = t.actor_id WHERE t.actor_id != :transition_actor '
            . 'ORDER BY t.created_at DESC, t.id DESC LIMIT 100) recent_transitions'
            . ') recent_events ORDER BY occurredAtRaw DESC, sourceId DESC, id DESC LIMIT 100',
            [':comment_actor' => $actorId, ':transition_actor' => $actorId],
        )->queryAll();
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
        ?string $attention = null,
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
                . 'OR LOCATE(:filter_query, COALESCE(r.department_name, \'Подразделение не указано\')) > 0 '
                . 'OR LOCATE(:filter_query, r.supplier) > 0 '
                . 'OR LOCATE(:filter_query, executor.display_name) > 0)';
            $filterParams[':filter_query'] = $query;
        }
        if ($attention !== null) {
            $queue = AttentionQueue::from($attention);
            $where[] = (new AttentionQueueScope())->condition($queue);
            $filterParams[':attention_actor'] = $actorId;
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
            'SELECT r.id, r.number, r.status, r.color, r.source, r.is_archived, r.product_name, r.manufacturer, '
            . 'r.supplier, r.sample_quantity, r.legacy_sample_quantity_raw, r.test_method, '
            . 'r.lock_version AS lockVersion, r.created_at, '
            . "u.display_name AS initiator_name, COALESCE(r.department_name, 'Подразделение не указано') AS department, "
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
        foreach ($items as &$archiveItem) {
            if ((int) $archiveItem['is_archived'] !== 1) {
                continue;
            }
            foreach (array_keys($archiveItem) as $key) {
                if (str_starts_with($key, 'can_')) {
                    $archiveItem[$key] = 0;
                }
            }
        }
        unset($archiveItem);

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

    /** @return array{categories: list<array{id: string, title: string, description: string, count: int}>} */
    public function attentionDashboard(int $actorId): array
    {
        $queues = AttentionQueue::cases();
        $scope = new AttentionQueueScope();
        $columns = [];
        foreach ($queues as $queue) {
            $columns[] = 'SUM(CASE WHEN ' . $scope->condition($queue) . ' THEN 1 ELSE 0 END) AS `'
                . $queue->value . '`';
        }
        $counts = $this->db->createCommand(
            'SELECT ' . implode(', ', $columns) . ' FROM {{%requests}} r',
            [':attention_actor' => $actorId],
        )->queryOne();

        $categories = [];
        foreach ($queues as $queue) {
            $count = (int) ($counts[$queue->value] ?? 0);
            if ($count === 0) {
                continue;
            }
            $categories[] = [
                'id' => $queue->value,
                'title' => $queue->title(),
                'description' => $queue->description(),
                'count' => $count,
            ];
        }

        return ['categories' => $categories];
    }

    /** @return array{item: array<string, mixed>, history: list<array<string, mixed>>, comments: list<array<string, mixed>>, commentsPage: array{hasMore: bool, nextBeforeId: int|null}, documents: list<array<string, mixed>>} */
    public function findDetails(int $requestId, int $actorId): array
    {
        $item = $this->db->createCommand(
            'SELECT r.id, r.number, r.status, r.color, r.source, r.is_archived, r.product_name, r.manufacturer, '
            . 'r.supplier, r.sample_quantity, r.legacy_sample_quantity_raw, r.test_method, '
            . 'r.lock_version AS lockVersion, '
            . "r.created_at, r.updated_at, u.display_name AS initiator_name, "
            . "COALESCE(r.department_name, 'Подразделение не указано') AS department, "
            . "(EXISTS(SELECT 1 FROM {{%users}} department_actor JOIN {{%user_roles}} department_ur "
            . "ON department_ur.user_id = department_actor.id JOIN {{%roles}} department_role "
            . "ON department_role.id = department_ur.role_id WHERE department_actor.id = :department_actor "
            . "AND department_actor.is_active = 1 AND department_role.code = 'administrator')) AS can_edit_department, "
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
                ':department_actor' => $actorId,
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
                ':active_suspend_actor' => $actorId,
                ':suspend_executor' => $actorId,
                ':suspend_executor_role' => $actorId,
                ':resume_manager' => $actorId,
                ':active_resume_actor' => $actorId,
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
        if ((int) $item['is_archived'] === 1) {
            foreach (array_keys($item) as $key) {
                if (str_starts_with($key, 'can_')) {
                    $item[$key] = 0;
                }
            }
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
            . "SELECT a.id, CASE WHEN a.event_type = 'request.department_changed' THEN 'audit' ELSE 'assignment' END AS kind, CASE a.event_type "
            . "WHEN 'request.executor_assigned' THEN 'assign_executor' "
            . "WHEN 'request.expert_claimed' THEN 'claim_expert' "
            . "WHEN 'request.expert_reassigned' THEN 'reassign_expert' "
            . "WHEN 'request.department_changed' THEN 'change_department' ELSE 'delete_report' END AS action, NULL, NULL, "
            . "a.rule_id, CASE WHEN a.event_type = 'request.report_deleted' THEN JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.reason')) ELSE NULL END, DATE_FORMAT(a.created_at, '%Y-%m-%dT%H:%i:%s.%fZ'), u.display_name, "
            . "CASE WHEN a.event_type = 'request.department_changed' THEN JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, '$.new_department_name')) "
            . "ELSE target_user.display_name END, NULL, NULL "
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
            . "'request.expert_reassigned', 'request.report_deleted', 'request.department_changed') "
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
            'SELECT d.id, d.comment_id AS commentId, d.document_type AS documentType, d.title, v.id AS versionId, v.version, v.original_name AS originalName, '
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
}

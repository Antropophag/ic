<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin;

use InvalidArgumentException;
use yii\db\Connection;

final class AuditQuery
{
    private const DENIED = [
        'request.security_decision_rejected', 'request.expert_assignment_denied',
        'request.executor_assignment_denied', 'request.color_mark_denied',
        'request.suspend_resume_denied', 'request.start_denied', 'request.create_denied',
        'request.reject_denied', 'request.withdraw_denied', 'request.document_download_rejected',
        'request.report_upload_rejected', 'request.report_deletion_rejected',
        'request.opinion_publish_rejected',
        'authentication.break_glass_denied', 'authentication.break_glass_configuration_error',
    ];

    private const TITLES = [
        'request.created' => 'Создана заявка',
        'request.security_decided' => 'Решение службы безопасности',
        'request.security_decision_rejected' => 'Решение службы безопасности отклонено',
        'request.comment_added' => 'Добавлен комментарий',
        'request.expert_claimed' => 'Эксперт принял заявку',
        'request.expert_reassigned' => 'Эксперт переназначен',
        'request.expert_assignment_denied' => 'Назначение эксперта отклонено',
        'request.executor_assigned' => 'Назначен исполнитель',
        'request.executor_assignment_denied' => 'Назначение исполнителя отклонено',
        'request.color_marked' => 'Изменён цвет заявки',
        'request.color_mark_denied' => 'Изменение метки отклонено',
        'request.started' => 'Испытания начаты', 'request.suspended' => 'Работы приостановлены',
        'request.resumed' => 'Работы возобновлены', 'request.suspend_resume_denied' => 'Изменение состояния отклонено',
        'request.start_denied' => 'Начало работ отклонено', 'request.create_denied' => 'Создание заявки отклонено',
        'request.rejected' => 'В испытаниях отказано', 'request.reject_denied' => 'Отказ по заявке отклонён',
        'request.withdrawn' => 'Заявка отозвана', 'request.withdraw_denied' => 'Отзыв заявки отклонён',
        'request.document_uploaded' => 'Документ загружен', 'request.report_uploaded' => 'Отчёт загружен',
        'request.report_deleted' => 'Отчёт удалён', 'request.opinion_published' => 'Заключение опубликовано',
        'request.document_downloaded' => 'Документ скачан', 'request.document_download_rejected' => 'Скачивание отклонено',
        'request.report_upload_rejected' => 'Загрузка отчёта отклонена',
        'request.report_deletion_rejected' => 'Удаление отчёта отклонено',
        'request.opinion_publish_rejected' => 'Публикация заключения отклонена',
        'request.imported' => 'Заявка импортирована', 'user.pre_provisioned' => 'Пользователь создан заранее',
        'request.department_changed' => 'Подразделение заявки изменено',
        'user.role_assigned' => 'Роль назначена', 'user.role_revoked' => 'Роль отозвана',
        'authentication.break_glass_succeeded' => 'Аварийный вход выполнен',
        'authentication.break_glass_denied' => 'Аварийный вход отклонён',
        'authentication.break_glass_configuration_error' => 'Ошибка конфигурации аварийного входа',
    ];

    private const SAFE_DETAILS = [
        'request.created' => ['to_status'],
        'request.security_decided' => ['decision'], 'request.comment_added' => ['comment_id'],
        'request.expert_claimed' => ['expert_id', 'assignment_id'], 'request.expert_reassigned' => ['expert_id', 'assignment_id'],
        'request.expert_assignment_denied' => ['expert_id'], 'request.executor_assigned' => ['executor_id', 'assignment_id'],
        'request.executor_assignment_denied' => ['executor_id'], 'request.color_marked' => ['color'],
        'request.started' => ['from_status', 'to_status'], 'request.suspended' => ['from_status', 'to_status'],
        'request.resumed' => ['from_status', 'to_status'], 'request.rejected' => ['from_status', 'to_status'],
        'request.withdrawn' => ['from_status', 'to_status'], 'request.document_uploaded' => ['document_id', 'version_id'],
        'request.report_uploaded' => ['document_id', 'version_id', 'version'], 'request.report_deleted' => ['document_id'],
        'request.opinion_published' => ['revision', 'document_version_id'],
        'request.document_downloaded' => ['version_id'], 'request.document_download_rejected' => ['version_id', 'outcome'],
        'request.imported' => ['legacyId'], 'user.pre_provisioned' => ['ad_login', 'display_name'],
        'request.department_changed' => ['old_department_name', 'new_department_name', 'old_department_external_id', 'new_department_external_id', 'source'],
        'user.role_assigned' => ['role_id'], 'user.role_revoked' => ['role_id'],
        'authentication.break_glass_succeeded' => ['authentication_type', 'ip', 'user_agent'],
        'authentication.break_glass_denied' => ['authentication_type', 'ip', 'user_agent', 'reason'],
        'authentication.break_glass_configuration_error' => ['authentication_type', 'ip', 'user_agent', 'reason'],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function findPage(array $filters): array
    {
        $where = [];
        $params = [];
        foreach (['actorId' => 'a.actor_id', 'eventType' => 'a.event_type', 'entityType' => 'a.entity_type', 'entityId' => 'a.entity_id'] as $key => $column) {
            if ($filters[$key] !== null) {
                $where[] = "$column = :$key";
                $params[":$key"] = $filters[$key];
            }
        }
        if ($filters['requestId'] !== null) {
            $where[] = "a.entity_type = 'request' AND a.entity_id = :requestId";
            $params[':requestId'] = $filters['requestId'];
        }
        if ($filters['dateFrom'] !== null) {
            $where[] = 'a.created_at >= :dateFrom';
            $params[':dateFrom'] = $filters['dateFrom'] . ' 00:00:00.000000';
        }
        if ($filters['dateTo'] !== null) {
            $where[] = 'a.created_at < DATE_ADD(:dateTo, INTERVAL 1 DAY)';
            $params[':dateTo'] = $filters['dateTo'] . ' 00:00:00.000000';
        }
        if ($filters['result'] !== 'all') {
            $marks = implode(', ', array_map(fn ($i) => ':denied' . $i, array_keys(self::DENIED)));
            foreach (self::DENIED as $i => $type) {
                $params[':denied' . $i] = $type;
            }
            $where[] = "(a.event_type IN ($marks) OR a.event_type LIKE :deniedSuffix)";
            $params[':deniedSuffix'] = '%\\_denied';
        }
        if ($filters['cursor'] !== null) {
            [$createdAt, $id] = $this->decodeCursor($filters['cursor']);
            $where[] = '(a.created_at < :cursorAt OR (a.created_at = :cursorAt AND a.id < :cursorId))';
            $params[':cursorAt'] = $createdAt;
            $params[':cursorId'] = $id;
        }
        $sql = 'SELECT a.id, a.event_type, a.entity_type, a.entity_id, a.rule_id, a.payload_json, a.created_at, '
            . 'actor.id AS actor_id, actor.display_name AS actor_name, actor.ad_login, target.display_name AS target_name, r.number AS request_number '
            . 'FROM {{%audit_events}} a JOIN {{%users}} actor ON actor.id = a.actor_id '
            . "LEFT JOIN {{%users}} target ON a.entity_type = 'user' AND target.id = a.entity_id "
            . "LEFT JOIN {{%requests}} r ON a.entity_type = 'request' AND r.id = a.entity_id "
            . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ') . 'ORDER BY a.created_at DESC, a.id DESC LIMIT :fetchLimit';
        $command = $this->db->createCommand($sql, $params);
        $command->bindValue(':fetchLimit', $filters['limit'] + 1, \PDO::PARAM_INT);
        $rows = $command->queryAll();
        $hasMore = count($rows) > $filters['limit'];
        if ($hasMore) {
            array_pop($rows);
        }
        $items = array_map(fn (array $row) => $this->present($row), $rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return ['items' => $items, 'hasMore' => $hasMore, 'nextCursor' => $hasMore && $last !== null ? $this->encodeCursor((string) $last['created_at'], (int) $last['id']) : null];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $type = (string) $row['event_type'];
        $payload = $row['payload_json'];
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        $safe = [];
        foreach (self::SAFE_DETAILS[$type] ?? [] as $key) {
            if (is_array($payload) && array_key_exists($key, $payload)) {
                $safe[$key] = $payload[$key];
            }
        }
        $entityType = (string) $row['entity_type'];
        $entityId = (int) $row['entity_id'];
        $label = match ($entityType) {
            'request' => 'Заявка №' . ($row['request_number'] ?? $entityId),
            'user' => 'Пользователь ' . ($row['target_name'] ?: ('№' . $entityId)),
            default => 'Объект ' . $entityType . ' №' . $entityId,
        };
        $title = self::TITLES[$type] ?? 'Системное событие';
        return ['id' => (int) $row['id'], 'createdAt' => $this->iso((string) $row['created_at']), 'eventType' => $type,
            'title' => $title, 'description' => (string) $row['actor_name'] . ': ' . $title, 'result' => $this->isDenied($type) ? 'denied' : 'success',
            'ruleId' => (string) $row['rule_id'], 'actor' => ['id' => (int) $row['actor_id'], 'displayName' => (string) $row['actor_name'], 'adLogin' => (string) $row['ad_login']],
            'entity' => ['type' => $entityType, 'id' => $entityId, 'label' => $label, 'requestId' => $entityType === 'request' ? $entityId : null], 'details' => $safe];
    }

    /** @return array{string, int} */
    private function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = $decoded === false ? null : json_decode($decoded, true);
        if (
            !is_array($data) || !isset($data['at'], $data['id']) || !is_string($data['at']) || !is_int($data['id'])
            || !$this->validTimestamp($data['at']) || $data['id'] < 1
        ) {
            throw new InvalidArgumentException('Invalid cursor');
        }
        return [$data['at'], $data['id']];
    }
    private function encodeCursor(string $at, int $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['at' => $at, 'id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
    private function iso(string $value): string
    {
        return str_replace(' ', 'T', $value) . 'Z';
    }
    private function isDenied(string $type): bool
    {
        return in_array($type, self::DENIED, true) || str_ends_with($type, '_denied');
    }
    private function validTimestamp(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s.u') === $value;
    }
}

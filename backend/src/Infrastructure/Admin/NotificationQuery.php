<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin;

use InvalidArgumentException;
use yii\db\Connection;

final class NotificationQuery
{
    private const TITLES = [
        'request.created' => 'Создание заявки', 'request.executor_assigned' => 'Назначение исполнителя',
        'request.report_uploaded' => 'Загрузка отчёта', 'request.expert_claimed' => 'Назначение эксперта',
        'request.expert_reassigned' => 'Переназначение эксперта', 'request.opinion_published' => 'Публикация заключения',
        'request.completed' => 'Завершение испытаний', 'request.returned' => 'Возврат на доработку',
        'request.commented' => 'Новый комментарий', 'request.rejected' => 'Отказ в испытаниях',
        'request.withdrawn' => 'Отзыв заявки',
    ];
    private const STATUS_LABELS = ['pending' => 'Ожидает', 'sending' => 'Отправляется', 'sent' => 'Отправлено', 'failed' => 'Ошибка'];

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
        foreach (['status' => 'n.status', 'requestId' => 'n.request_id', 'eventType' => 'n.event_type'] as $key => $column) {
            if ($filters[$key] !== null) {
                $where[] = "$column = :$key";
                $params[":$key"] = $filters[$key];
            }
        }
        if ($filters['recipient'] !== null) {
            $where[] = '(LOCATE(:recipient, n.recipient_email) > 0 OR LOCATE(:recipient, n.recipient_name) > 0)';
            $params[':recipient'] = $filters['recipient'];
        }
        if ($filters['dateFrom'] !== null) {
            $where[] = 'n.created_at >= :dateFrom';
            $params[':dateFrom'] = $filters['dateFrom'] . ' 00:00:00.000000';
        }
        if ($filters['dateTo'] !== null) {
            $where[] = 'n.created_at < DATE_ADD(:dateTo, INTERVAL 1 DAY)';
            $params[':dateTo'] = $filters['dateTo'] . ' 00:00:00.000000';
        }
        if ($filters['problematic'] === '1' || $filters['problematic'] === 1 || $filters['problematic'] === true) {
            $where[] = "(n.status = 'failed' OR (n.status IN ('pending', 'sending') AND n.attempts > 1) "
                . "OR (n.status = 'sending' AND n.next_attempt_at <= :now) "
                . "OR (n.status = 'pending' AND n.next_attempt_at <= DATE_SUB(:now, INTERVAL 300 SECOND)))";
            $params[':now'] = gmdate('Y-m-d H:i:s.u');
        }
        if ($filters['cursor'] !== null) {
            [$createdAt, $id] = $this->decodeCursor($filters['cursor']);
            $where[] = '(n.created_at < :cursorAt OR (n.created_at = :cursorAt AND n.id < :cursorId))';
            $params[':cursorAt'] = $createdAt;
            $params[':cursorId'] = $id;
        }
        // Deliberately excludes body. This is also asserted by integration/HTTP contract tests.
        $sql = 'SELECT n.id, n.request_id, r.number AS request_number, n.event_type, n.recipient_email, n.recipient_name, '
            . 'n.subject, n.status, n.attempts, n.next_attempt_at, n.last_error, n.created_at, n.sent_at '
            . 'FROM {{%notification_outbox}} n JOIN {{%requests}} r ON r.id = n.request_id '
            . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ') . 'ORDER BY n.created_at DESC, n.id DESC LIMIT :fetchLimit';
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
        $status = (string) $row['status'];
        $attempts = (int) $row['attempts'];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nextAttempt = new \DateTimeImmutable((string) $row['next_attempt_at'], new \DateTimeZone('UTC'));
        $due = $nextAttempt <= $now;
        $pendingStale = $status === 'pending' && $nextAttempt <= $now->modify('-300 seconds');
        $health = $status === 'failed'
            ? 'failed'
            : (($status === 'sending' && $due) || $pendingStale
                ? 'stale'
                : (in_array($status, ['pending', 'sending'], true) && $attempts > 1 ? 'retrying' : 'normal'));
        return ['id' => (int) $row['id'], 'requestId' => (int) $row['request_id'], 'requestLabel' => 'Заявка №' . $row['request_number'],
            'eventType' => (string) $row['event_type'], 'eventTitle' => self::TITLES[$row['event_type']] ?? 'Системное уведомление',
            'recipient' => ['email' => (string) $row['recipient_email'], 'name' => (string) $row['recipient_name']],
            'subject' => (string) $row['subject'], 'status' => $status, 'statusLabel' => self::STATUS_LABELS[$status] ?? 'Неизвестно',
            'attempts' => $attempts, 'createdAt' => $this->iso((string) $row['created_at']),
            'nextAttemptAt' => $this->iso((string) $row['next_attempt_at']), 'sentAt' => $row['sent_at'] === null ? null : $this->iso((string) $row['sent_at']),
            'lastError' => $this->safeError($row['last_error']), 'health' => $health,
            'isFailed' => $status === 'failed', 'isRetrying' => $health === 'retrying', 'isStale' => $health === 'stale'];
    }

    private function safeError(mixed $error): ?string
    {
        if (!is_string($error) || trim($error) === '') {
            return null;
        }
        // Worker stores arbitrary exception messages: expose only a neutral class, never raw paths/credentials/transport details.
        return 'Ошибка SMTP';
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
    private function validTimestamp(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s.u') === $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Request;

use App\Application\Request\AddCommentResult;
use App\Application\Request\Port\RequestCommentGateway;
use App\Domain\Request\RequestStatus;
use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutbox;
use yii\db\Connection;

final readonly class RequestCommentPersistenceAdapter implements RequestCommentGateway
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

    public function statusForActiveActorForUpdate(int $requestId, int $actorId): ?RequestStatus
    {
        $status = $this->db->createCommand(
            'SELECT r.status FROM {{%requests}} r '
            . 'JOIN {{%users}} u ON u.id = :actor_id AND u.is_active = 1 '
            . 'WHERE r.id = :request_id FOR UPDATE',
            [':request_id' => $requestId, ':actor_id' => $actorId],
        )->queryScalar();

        return $status === false ? null : RequestStatus::from((string) $status);
    }

    public function commentTimestamp(): string
    {
        return Clock::now();
    }

    public function persistComment(int $requestId, int $actorId, string $body, string $createdAt): int
    {
        $this->db->createCommand()->insert('{{%request_comments}}', [
            'request_id' => $requestId,
            'author_id' => $actorId,
            'body' => $body,
            'created_at' => $createdAt,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function recordCommentAdded(int $requestId, int $actorId, int $commentId, string $createdAt): void
    {
        $this->db->createCommand()->insert('{{%audit_events}}', [
            'event_type' => 'request.comment_added',
            'entity_type' => 'request',
            'entity_id' => $requestId,
            'actor_id' => $actorId,
            'rule_id' => 'COM-003',
            'payload_json' => ['comment_id' => $commentId],
            'created_at' => $createdAt,
        ])->execute();
    }

    public function enqueueCommentNotifications(int $requestId, int $actorId): void
    {
        $outbox = new NotificationOutbox($this->db);
        foreach ($this->processParticipants($requestId) as $participant) {
            if ($participant['id'] === $actorId) {
                continue;
            }
            $outbox->enqueue(
                $requestId,
                'request.commented',
                $participant['email'],
                $participant['name'],
                'Новый комментарий по заявке',
                'В заявке появился новый комментарий. Откройте заявку в портале, чтобы прочитать его.',
            );
        }
    }

    public function commentResult(int $commentId): AddCommentResult
    {
        $comment = $this->db->createCommand(
            "SELECT c.id, c.body, DATE_FORMAT(c.created_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS createdAt, "
            . 'u.display_name AS authorName FROM {{%request_comments}} c '
            . 'JOIN {{%users}} u ON u.id = c.author_id WHERE c.id = :id',
            [':id' => $commentId],
        )->queryOne();

        return new AddCommentResult(
            (int) $comment['id'],
            (string) $comment['body'],
            (string) $comment['createdAt'],
            (string) $comment['authorName'],
        );
    }

    /** @return list<array{id: int, email: string, name: string}> */
    private function processParticipants(int $requestId): array
    {
        $participants = $this->db->createCommand(
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

        return array_map(static fn (array $participant): array => [
            'id' => (int) $participant['id'],
            'email' => (string) $participant['email'],
            'name' => (string) $participant['name'],
        ], $participants);
    }
}

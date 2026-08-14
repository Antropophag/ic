<?php

declare(strict_types=1);

namespace App\Infrastructure\Request;

use App\Domain\Request\CommentPolicy;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutbox;
use yii\db\Connection;

final class RequestRepository
{
    public function __construct(private readonly Connection $db)
    {
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
}

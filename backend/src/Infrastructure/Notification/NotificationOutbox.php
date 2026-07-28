<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use yii\db\Connection;

final class NotificationOutbox
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Ставит письмо в очередь в текущей транзакционной границе вызывающего
     * кода (NTF-002). Содержимое рендерится сразу, чтобы запись была
     * самодостаточной и не зависела от состояния заявки на момент отправки.
     */
    public function enqueue(
        int $requestId,
        string $eventType,
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $body,
    ): void {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->createCommand()->insert('{{%notification_outbox}}', [
            'request_id' => $requestId,
            'event_type' => $eventType,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'subject' => $subject,
            'body' => $body,
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
        ])->execute();
    }
}

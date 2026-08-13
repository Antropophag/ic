<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Clock;
use yii\db\Connection;

final class NotificationOutbox
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Ставит письмо в очередь в текущей транзакционной границе вызывающего
     * кода (NTF-002). Семантические ссылки на документы хранятся отдельно;
     * bearer credentials формируются только на границе доставки (ACL-005).
     *
     * @param list<array{label: string, documentVersionId: int}> $documentLinks
     */
    public function enqueue(
        int $requestId,
        string $eventType,
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $body,
        array $documentLinks = [],
    ): void {
        $now = Clock::now();
        $this->db->createCommand()->insert('{{%notification_outbox}}', [
            'request_id' => $requestId,
            'event_type' => $eventType,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'subject' => $subject,
            'body' => $body,
            'payload_json' => ['documentLinks' => $documentLinks],
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
        ])->execute();
    }
}

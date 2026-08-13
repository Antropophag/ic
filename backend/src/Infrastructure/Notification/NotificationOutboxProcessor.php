<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Clock;
use Yii;
use yii\db\Connection;

final class NotificationOutboxProcessor
{
    private const MAX_ATTEMPTS = 5;
    private const CLAIM_LEASE_SECONDS = 300;
    private const BACKOFF_BASE_SECONDS = 60;
    private const BACKOFF_MAX_SECONDS = 3600;

    /** @var callable(int, string, string, string, string): void */
    private $sender;

    /**
     * @param null|callable(int, string, string, string, string): void $sender
     */
    public function __construct(
        private readonly Connection $db,
        ?callable $sender = null,
    ) {
        $mailer = new Mailer();
        $this->sender = $sender ?? $mailer->send(...);
    }

    /**
     * @param null|callable(): bool $shouldContinue
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function processAvailableBatch(
        int $limit,
        bool $includeFailed = false,
        ?callable $shouldContinue = null,
    ): array {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Notification batch limit must be positive.');
        }

        $statuses = $includeFailed ? "'pending', 'sending', 'failed'" : "'pending', 'sending'";
        $ids = $this->db->createCommand(
            "SELECT id FROM {{%notification_outbox}} WHERE status IN ({$statuses}) "
            . 'AND next_attempt_at <= :now ORDER BY next_attempt_at LIMIT :limit',
            [':now' => Clock::now(), ':limit' => $limit],
        )->queryColumn();

        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($ids as $id) {
            if ($shouldContinue !== null && !$shouldContinue()) {
                break;
            }
            $this->processOne((int) $id, $includeFailed, $result);
        }

        return $result;
    }

    /**
     * @param array{sent: int, failed: int, skipped: int} $result
     */
    private function processOne(int $id, bool $includeFailed, array &$result): void
    {
        $now = Clock::now();
        $statuses = $includeFailed ? "'pending', 'sending', 'failed'" : "'pending', 'sending'";
        $claimed = $this->db->createCommand(
            "UPDATE {{%notification_outbox}} SET status = 'sending', attempts = attempts + 1, "
            . 'next_attempt_at = :lease_until '
            . "WHERE id = :id AND status IN ({$statuses}) AND next_attempt_at <= :now",
            [
                ':id' => $id,
                ':now' => $now,
                ':lease_until' => gmdate('Y-m-d H:i:s.u', time() + self::CLAIM_LEASE_SECONDS),
            ],
        )->execute();
        if ($claimed === 0) {
            $result['skipped']++;
            return;
        }

        $row = $this->db->createCommand(
            'SELECT request_id, recipient_email, recipient_name, subject, body, payload_json, attempts '
            . 'FROM {{%notification_outbox}} WHERE id = :id',
            [':id' => $id],
        )->queryOne();
        if ($row === false) {
            $result['skipped']++;
            return;
        }

        try {
            $payload = is_array($row['payload_json'])
                ? $row['payload_json']
                : json_decode((string) $row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            $documentLinks = $this->documentLinks($payload);
            $body = (new NotificationDownloadLinks($this->db))->appendToBody(
                $id,
                (string) $row['body'],
                $documentLinks,
            );
            [$recipientEmail, $subject, $body] = NotificationTestRedirect::apply(
                (string) $row['recipient_email'],
                (string) $row['recipient_name'],
                (string) $row['subject'],
                $body,
                getenv('NOTIFICATION_TEST_REDIRECT_EMAIL') ?: null,
            );
            ($this->sender)(
                (int) $row['request_id'],
                $recipientEmail,
                (string) $row['recipient_name'],
                $subject,
                $body,
            );
            $this->db->createCommand()->update(
                '{{%notification_outbox}}',
                [
                    'status' => 'sent',
                    'sent_at' => Clock::now(),
                    'last_error' => null,
                    'body' => '[доставлено, тело удалено после отправки]',
                ],
                ['id' => $id, 'status' => 'sending'],
            )->execute();
            $result['sent']++;
        } catch (\Throwable $error) {
            $this->recordFailure(
                $id,
                (int) $row['attempts'],
                $error,
                $error instanceof InvalidNotificationPayload || $error instanceof \JsonException,
            );
            $result['failed']++;
        }
    }

    /** @return list<array{label: string, documentVersionId: int}> */
    private function documentLinks(mixed $payload): array
    {
        if (!is_array($payload) || !isset($payload['documentLinks']) || !is_array($payload['documentLinks'])) {
            throw new InvalidNotificationPayload('Notification payload has an invalid documentLinks collection.');
        }

        $links = [];
        foreach ($payload['documentLinks'] as $link) {
            if (
                !is_array($link)
                || !isset($link['label'], $link['documentVersionId'])
                || !is_string($link['label'])
                || $link['label'] === ''
                || !is_int($link['documentVersionId'])
                || $link['documentVersionId'] <= 0
            ) {
                throw new InvalidNotificationPayload('Notification payload contains an invalid document link.');
            }
            $links[] = [
                'label' => $link['label'],
                'documentVersionId' => $link['documentVersionId'],
            ];
        }

        return $links;
    }

    private function recordFailure(int $id, int $attempts, \Throwable $error, bool $terminal = false): void
    {
        if ($terminal || $attempts >= self::MAX_ATTEMPTS) {
            $values = [
                'status' => 'failed',
                'next_attempt_at' => Clock::now(),
                'last_error' => $error->getMessage(),
            ];
        } else {
            $delay = min(self::BACKOFF_BASE_SECONDS * (2 ** ($attempts - 1)), self::BACKOFF_MAX_SECONDS);
            $values = [
                'status' => 'pending',
                'next_attempt_at' => gmdate('Y-m-d H:i:s.u', time() + $delay),
                'last_error' => $error->getMessage(),
            ];
        }

        $this->db->createCommand()->update(
            '{{%notification_outbox}}',
            $values,
            ['id' => $id, 'status' => 'sending'],
        )->execute();
        Yii::error([
            'message' => 'Не удалось отправить уведомление.',
            'notificationId' => $id,
            'exception' => $error,
        ], __METHOD__);
        Yii::getLogger()->flush(true);
    }
}

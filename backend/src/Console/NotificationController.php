<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Clock;
use App\Infrastructure\Notification\Mailer;
use App\Infrastructure\Notification\NotificationTestRedirect;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class NotificationController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const BATCH_SIZE = 50;
    // Аренда захвата: если обработчик упал после захвата записи (status =
    // sending), запись становится доступной для повторного захвата не раньше
    // чем через этот интервал — вместо того, чтобы зависнуть навсегда.
    private const CLAIM_LEASE_SECONDS = 300;
    private const BACKOFF_BASE_SECONDS = 60;
    private const BACKOFF_MAX_SECONDS = 3600;

    public function actionSend(): int
    {
        $mailer = new Mailer();
        $sent = 0;
        $failed = 0;

        $ids = Yii::$app->db->createCommand(
            "SELECT id FROM {{%notification_outbox}} WHERE status IN ('pending', 'sending') "
            . 'AND next_attempt_at <= :now ORDER BY next_attempt_at LIMIT :limit',
            [':now' => Clock::now(), ':limit' => self::BATCH_SIZE],
        )->queryColumn();

        foreach ($ids as $id) {
            $id = (int) $id;
            $now = Clock::now();

            // Атомарный захват: срабатывает и на обычный pending, и на
            // просроченную аренду sending (зависший после сбоя обработчик).
            // next_attempt_at сразу сдвигается вперёд — это же поле служит
            // границей аренды на время обработки этой записи.
            $claimed = Yii::$app->db->createCommand(
                "UPDATE {{%notification_outbox}} SET status = 'sending', attempts = attempts + 1, "
                . 'next_attempt_at = :lease_until '
                . "WHERE id = :id AND status IN ('pending', 'sending') AND next_attempt_at <= :now",
                [
                    ':id' => $id,
                    ':now' => $now,
                    ':lease_until' => gmdate('Y-m-d H:i:s.u', time() + self::CLAIM_LEASE_SECONDS),
                ],
            )->execute();
            if ($claimed === 0) {
                continue;
            }

            $row = Yii::$app->db->createCommand(
                'SELECT recipient_email, recipient_name, subject, body, attempts '
                . 'FROM {{%notification_outbox}} WHERE id = :id',
                [':id' => $id],
            )->queryOne();
            if ($row === false) {
                continue;
            }

            try {
                [$recipientEmail, $subject, $body] = NotificationTestRedirect::apply(
                    (string) $row['recipient_email'],
                    (string) $row['recipient_name'],
                    (string) $row['subject'],
                    (string) $row['body'],
                    getenv('NOTIFICATION_TEST_REDIRECT_EMAIL') ?: null,
                );
                $mailer->send($recipientEmail, (string) $row['recipient_name'], $subject, $body);
                // ACL-005/AUD-003: тело письма могло содержать одноразовые
                // download-токены (см. DocumentDownloadUrl) — после успешной
                // отправки они больше не нужны и не должны бессрочно
                // храниться в БД открытым текстом.
                Yii::$app->db->createCommand()->update(
                    '{{%notification_outbox}}',
                    [
                        'status' => 'sent',
                        'sent_at' => Clock::now(),
                        'last_error' => null,
                        'body' => '[доставлено, тело удалено после отправки]',
                    ],
                    ['id' => $id, 'status' => 'sending'],
                )->execute();
                $sent++;
            } catch (\Throwable $error) {
                $attempts = (int) $row['attempts'];
                if ($attempts >= self::MAX_ATTEMPTS) {
                    Yii::$app->db->createCommand()->update(
                        '{{%notification_outbox}}',
                        ['status' => 'failed', 'last_error' => $error->getMessage()],
                        ['id' => $id, 'status' => 'sending'],
                    )->execute();
                } else {
                    $delay = min(self::BACKOFF_BASE_SECONDS * (2 ** ($attempts - 1)), self::BACKOFF_MAX_SECONDS);
                    Yii::$app->db->createCommand()->update(
                        '{{%notification_outbox}}',
                        [
                            'status' => 'pending',
                            'next_attempt_at' => gmdate('Y-m-d H:i:s.u', time() + $delay),
                            'last_error' => $error->getMessage(),
                        ],
                        ['id' => $id, 'status' => 'sending'],
                    )->execute();
                }
                $failed++;
                Yii::error([
                    'message' => 'Не удалось отправить уведомление.',
                    'notificationId' => $id,
                    'exception' => $error,
                ], __METHOD__);
            }
        }

        $this->stdout("Отправлено: {$sent}, ошибок: {$failed}.\n");
        return ExitCode::OK;
    }
}

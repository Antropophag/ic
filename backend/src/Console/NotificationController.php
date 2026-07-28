<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Notification\Mailer;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

final class NotificationController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const BATCH_SIZE = 50;

    public function actionSend(): int
    {
        $mailer = new Mailer();
        $sent = 0;
        $failed = 0;

        $ids = Yii::$app->db->createCommand(
            'SELECT id FROM {{%notification_outbox}} '
            . "WHERE status = 'pending' ORDER BY created_at LIMIT :limit",
            [':limit' => self::BATCH_SIZE],
        )->queryColumn();

        foreach ($ids as $id) {
            $id = (int) $id;

            // NTF-003: атомарный захват записи предотвращает повторную
            // отправку при параллельном или повторном запуске команды.
            $claimed = Yii::$app->db->createCommand(
                "UPDATE {{%notification_outbox}} SET status = 'sending', attempts = attempts + 1 "
                . "WHERE id = :id AND status = 'pending'",
                [':id' => $id],
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
                $mailer->send(
                    (string) $row['recipient_email'],
                    (string) $row['recipient_name'],
                    (string) $row['subject'],
                    (string) $row['body'],
                );
                Yii::$app->db->createCommand()->update(
                    '{{%notification_outbox}}',
                    ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s.u'), 'last_error' => null],
                    ['id' => $id],
                )->execute();
                $sent++;
            } catch (\Throwable $error) {
                $nextStatus = (int) $row['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
                Yii::$app->db->createCommand()->update(
                    '{{%notification_outbox}}',
                    ['status' => $nextStatus, 'last_error' => $error->getMessage()],
                    ['id' => $id],
                )->execute();
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

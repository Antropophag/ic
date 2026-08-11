<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Infrastructure\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\db\Connection;

final class UserActivityRecorder
{
    public const ACTIVITY_THROTTLE_SECONDS = 300;

    public function __construct(private readonly Connection $db)
    {
    }

    public function recordLogin(int $userId): void
    {
        try {
            $this->db->createCommand()->update(
                '{{%users}}',
                ['last_login_at' => Clock::now()],
                ['id' => $userId],
            )->execute();
        } catch (\Throwable $error) {
            Yii::warning([
                'event' => 'user_login_timestamp_update_failed',
                'user_id' => $userId,
                'error_class' => $error::class,
            ], __METHOD__);
        }
    }

    public function recordActivity(int $userId, ?string $knownActivityAt): void
    {
        $now = Clock::now();
        $cutoff = (new DateTimeImmutable($now, new DateTimeZone('UTC')))
            ->modify('-' . self::ACTIVITY_THROTTLE_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s.u');
        if ($knownActivityAt !== null && $knownActivityAt >= $cutoff) {
            return;
        }

        $this->db->createCommand(
            'UPDATE {{%users}} SET last_activity_at = :now '
            . 'WHERE id = :id AND (last_activity_at IS NULL OR last_activity_at < :cutoff)',
            [':now' => $now, ':id' => $userId, ':cutoff' => $cutoff],
        )->execute();
    }
}

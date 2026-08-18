<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Infrastructure\Clock;
use yii\db\Connection;

final readonly class DatabaseAiFileCleanupQueue implements AiFileCleanupQueue
{
    private const RETENTION = '+24 hours';

    public function __construct(private Connection $db)
    {
    }

    public function schedule(string $externalFileId, \Throwable $error): void
    {
        $now = Clock::now();
        $expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
            ->modify(self::RETENTION)->format('Y-m-d H:i:s.u');
        $this->db->createCommand(
            'INSERT INTO {{%ai_file_cleanup}} '
            . '(external_file_id, attempts, next_attempt_at, expires_at, last_error_class, created_at, updated_at) '
            . 'VALUES (:file_id, 0, :now, :expires, :error, :now, :now) '
            . 'ON DUPLICATE KEY UPDATE next_attempt_at = VALUES(next_attempt_at), '
            . 'expires_at = VALUES(expires_at), last_error_class = VALUES(last_error_class), updated_at = VALUES(updated_at)',
            [':file_id' => $externalFileId, ':now' => $now, ':expires' => $expiresAt, ':error' => $error::class],
        )->execute();
    }
}

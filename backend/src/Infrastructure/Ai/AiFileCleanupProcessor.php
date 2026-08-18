<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Infrastructure\Clock;
use yii\db\Connection;

final readonly class AiFileCleanupProcessor
{
    public function __construct(private Connection $db, private OpenWebUiTransport $transport)
    {
    }

    /** @return array{deleted: int, failed: int, expired: int} */
    public function processAvailableBatch(int $limit = 20): array
    {
        $lockName = 'ai-file-cleanup-worker';
        if ((int) $this->db->createCommand('SELECT GET_LOCK(:name, 0)', [':name' => $lockName])->queryScalar() !== 1) {
            return ['deleted' => 0, 'failed' => 0, 'expired' => 0];
        }
        try {
            return $this->processLockedBatch($limit);
        } finally {
            $this->db->createCommand('SELECT RELEASE_LOCK(:name)', [':name' => $lockName])->queryScalar();
        }
    }

    /** @return array{deleted: int, failed: int, expired: int} */
    private function processLockedBatch(int $limit): array
    {
        $now = Clock::now();
        $expired = $this->db->createCommand(
            'DELETE FROM {{%ai_file_cleanup}} WHERE expires_at <= :now',
            [':now' => $now],
        )->execute();
        $expired += $this->db->createCommand(
            'DELETE FROM {{%ai_idempotency_requests}} WHERE expires_at <= :now',
            [':now' => $now],
        )->execute();
        $rows = $this->db->createCommand(
            'SELECT id, external_file_id, attempts FROM {{%ai_file_cleanup}} '
            . 'WHERE next_attempt_at <= :now AND expires_at > :now ORDER BY id LIMIT ' . max(1, min(100, $limit)),
            [':now' => $now],
        )->queryAll();
        $deleted = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $this->transport->beginOperation();
                $this->transport->deleteFile((string) $row['external_file_id']);
                $this->db->createCommand()->delete('{{%ai_file_cleanup}}', ['id' => (int) $row['id']])->execute();
                ++$deleted;
            } catch (\Throwable $error) {
                $attempts = (int) $row['attempts'] + 1;
                $delay = min(3600, 30 * (2 ** min(7, $attempts - 1)));
                $next = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
                    ->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s.u');
                $this->db->createCommand()->update('{{%ai_file_cleanup}}', [
                    'attempts' => $attempts, 'next_attempt_at' => $next,
                    'last_error_class' => $error::class, 'updated_at' => $now,
                ], ['id' => (int) $row['id']])->execute();
                ++$failed;
            } finally {
                $this->transport->disconnect();
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed, 'expired' => $expired];
    }
}

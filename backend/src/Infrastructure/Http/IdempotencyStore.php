<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Clock;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\web\HttpException;

final class IdempotencyStore
{
    public const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{15,127}\z/D';
    public const MAX_RESPONSE_BYTES = 65_535;
    private const RETENTION = '+24 hours';
    private const CLEANUP_CHANCE = 100;
    private const LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param callable(): mixed $operation
     * @return array{body: mixed, statusCode: int, location: string|null, replayed: bool}
     */
    public function execute(
        int $actorId,
        string $method,
        string $route,
        string $key,
        string $requestHash,
        callable $operation,
        callable $statusCode,
        callable $location,
    ): array {
        if (random_int(1, self::CLEANUP_CHANCE) === 1) {
            $this->cleanupExpired();
        }
        $lockName = hash('sha256', implode("\0", [(string) $actorId, $method, $route, $key]));
        $lockAcquired = (int) $this->db->createCommand(
            'SELECT GET_LOCK(:name, :timeout)',
            [':name' => $lockName, ':timeout' => self::LOCK_TIMEOUT_SECONDS],
        )->queryScalar() === 1;
        if (!$lockAcquired) {
            throw new IdempotencyConflict('Предыдущий запрос с этим Idempotency-Key ещё выполняется.');
        }

        $transaction = null;
        $recordId = null;
        try {
            $transaction = $this->db->beginTransaction();
            $now = Clock::now();
            $keyHash = hash('sha256', $key);
            try {
                $this->db->createCommand()->insert('{{%idempotency_requests}}', [
                    'actor_id' => $actorId,
                    'http_method' => $method,
                    'route' => $route,
                    'key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'created_at' => $now,
                    'expires_at' => (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
                        ->modify(self::RETENTION)->format('Y-m-d H:i:s.u'),
                ])->execute();
                $recordId = (int) $this->db->getLastInsertID();
            } catch (IntegrityException $integrityError) {
                $record = $this->db->createCommand(
                    'SELECT id, request_hash, status_code, response_json, location, expires_at '
                    . 'FROM {{%idempotency_requests}} WHERE actor_id = :actor_id '
                    . 'AND http_method = :method AND route = :route AND key_hash = :key_hash FOR UPDATE',
                    [':actor_id' => $actorId, ':method' => $method, ':route' => $route, ':key_hash' => $keyHash],
                )->queryOne();
                if ($record === false) {
                    throw $integrityError;
                }
                if ((string) $record['expires_at'] < $now) {
                    $this->db->createCommand()->delete(
                        '{{%idempotency_requests}}',
                        ['id' => (int) $record['id']],
                    )->execute();
                    $this->db->createCommand()->insert('{{%idempotency_requests}}', [
                        'actor_id' => $actorId,
                        'http_method' => $method,
                        'route' => $route,
                        'key_hash' => $keyHash,
                        'request_hash' => $requestHash,
                        'created_at' => $now,
                        'expires_at' => (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
                            ->modify(self::RETENTION)->format('Y-m-d H:i:s.u'),
                    ])->execute();
                    $recordId = (int) $this->db->getLastInsertID();
                } elseif (!hash_equals((string) $record['request_hash'], $requestHash)) {
                    throw new IdempotencyConflict('Idempotency-Key уже использован для другого запроса.');
                } elseif ($record['status_code'] === null || $record['response_json'] === null) {
                    throw new \RuntimeException('Idempotency request is incomplete.');
                } else {
                    $body = json_decode((string) $record['response_json'], true, 512, JSON_THROW_ON_ERROR);
                    $transaction->commit();
                    return ['body' => $body, 'statusCode' => (int) $record['status_code'],
                        'location' => $record['location'] === null ? null : (string) $record['location'], 'replayed' => true];
                }
            }

            $body = $operation();
            $code = (int) $statusCode();
            if ($code < 200 || $code >= 300) {
                $transaction->rollBack();
                return ['body' => $body, 'statusCode' => $code, 'location' => null, 'replayed' => false];
            }
            $responseJson = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($responseJson) > self::MAX_RESPONSE_BYTES) {
                throw new \LengthException('Idempotent response exceeds the storage limit.');
            }
            $responseLocation = $location();
            if ($responseLocation !== null && strlen($responseLocation) > 2_048) {
                throw new \LengthException('Idempotent Location header exceeds the storage limit.');
            }
            $this->db->createCommand()->update('{{%idempotency_requests}}', [
                'status_code' => $code,
                'response_json' => $responseJson,
                'location' => $responseLocation,
            ], ['id' => $recordId])->execute();
            $transaction->commit();
            return ['body' => $body, 'statusCode' => $code, 'location' => $responseLocation, 'replayed' => false];
        } catch (HttpException $error) {
            if ($error->statusCode < 500 && $transaction?->isActive && $recordId !== null) {
                $this->db->createCommand()->delete('{{%idempotency_requests}}', ['id' => $recordId])->execute();
                $transaction->commit();
                throw $error;
            }
            if ($transaction?->isActive) {
                $transaction->rollBack();
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($transaction?->isActive) {
                $transaction->rollBack();
            }
            throw $error;
        } finally {
            $this->db->createCommand('SELECT RELEASE_LOCK(:name)', [':name' => $lockName])->queryScalar();
        }
    }

    public function cleanupExpired(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Cleanup limit must be between 1 and 1000.');
        }
        return $this->db->createCommand(
            "DELETE FROM {{%idempotency_requests}} WHERE expires_at < :now ORDER BY expires_at LIMIT {$limit}",
            [':now' => Clock::now()],
        )->execute();
    }
}

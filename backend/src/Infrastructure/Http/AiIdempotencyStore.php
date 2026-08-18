<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\Ai\AiRequestLifecycle;
use App\Infrastructure\Clock;
use yii\db\Connection;
use yii\web\HttpException;

final class AiIdempotencyStore implements AiRequestLifecycle
{
    private const RETENTION = '+24 hours';

    public function __construct(
        private readonly Connection $db,
        private readonly int $perUserLimit,
        private readonly int $globalLimit,
        private readonly int $leaseSeconds,
    ) {
        if ($perUserLimit < 1 || $globalLimit < $perUserLimit || $leaseSeconds < 1) {
            throw new \InvalidArgumentException('Invalid AI idempotency limits.');
        }
    }

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
        $reservation = $this->reserve($actorId, $method, $route, $key, $requestHash);
        if (isset($reservation['body'])) {
            return $reservation;
        }
        $recordId = $reservation['recordId'];
        try {
            if ($this->db->getTransaction() !== null) {
                throw new \LogicException('AI operation must run outside a database transaction.');
            }
            $body = $operation();
            $code = (int) $statusCode();
            $responseLocation = $location();
            $this->finalize($recordId, $body, $code, $responseLocation);
            return ['body' => $body, 'statusCode' => $code, 'location' => $responseLocation, 'replayed' => false];
        } catch (\Throwable $error) {
            $code = $error instanceof HttpException ? $error->statusCode : 503;
            $message = $error instanceof HttpException
                ? $error->getMessage()
                : 'AI-сервис временно недоступен. Повторите попытку позже.';
            $this->finalize($recordId, ['message' => $message], $code, null);
            throw $error;
        }
    }

    /** @return array{recordId: int}|array{body: mixed, statusCode: int, location: string|null, replayed: true} */
    private function reserve(int $actorId, string $method, string $route, string $key, string $requestHash): array
    {
        $lockName = 'ai-idempotency-capacity';
        if ((int) $this->db->createCommand('SELECT GET_LOCK(:name, 5)', [':name' => $lockName])->queryScalar() !== 1) {
            throw new IdempotencyConflict('Не удалось зарезервировать AI-анализ. Повторите попытку.');
        }
        try {
            $transaction = $this->db->beginTransaction();
            try {
                $now = Clock::now();
                $this->db->createCommand()->delete(
                    '{{%ai_idempotency_requests}}',
                    ['<', 'expires_at', $now],
                )->execute();
                $keyHash = hash('sha256', $key);
                $record = $this->db->createCommand(
                    'SELECT id, request_hash, state, status_code, response_json, location, lease_expires_at '
                    . 'FROM {{%ai_idempotency_requests}} WHERE actor_id = :actor AND http_method = :method '
                    . 'AND route = :route AND key_hash = :key FOR UPDATE',
                    [':actor' => $actorId, ':method' => $method, ':route' => $route, ':key' => $keyHash],
                )->queryOne();
                if ($record !== false && !hash_equals((string) $record['request_hash'], $requestHash)) {
                    throw new IdempotencyConflict('Idempotency-Key уже использован для другого запроса.');
                }
                if ($record !== false && $record['state'] === 'completed') {
                    $body = json_decode((string) $record['response_json'], true, 512, JSON_THROW_ON_ERROR);
                    $transaction->commit();
                    return ['body' => $body, 'statusCode' => (int) $record['status_code'],
                        'location' => $record['location'] === null ? null : (string) $record['location'], 'replayed' => true];
                }
                if ($record !== false && $record['state'] === 'in_progress' && (string) $record['lease_expires_at'] > $now) {
                    throw new IdempotencyConflict('Этот AI-анализ уже выполняется.');
                }
                if ($record !== false) {
                    $this->db->createCommand()->delete('{{%ai_idempotency_requests}}', ['id' => (int) $record['id']])->execute();
                }
                $this->assertCapacity($actorId, $route, $now);
                $expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
                    ->modify('+' . $this->leaseSeconds . ' seconds')->format('Y-m-d H:i:s.u');
                $retainedUntil = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
                    ->modify(self::RETENTION)->format('Y-m-d H:i:s.u');
                $this->db->createCommand()->insert('{{%ai_idempotency_requests}}', [
                    'actor_id' => $actorId, 'http_method' => $method, 'route' => $route,
                    'key_hash' => $keyHash, 'request_hash' => $requestHash, 'state' => 'in_progress',
                    'lease_expires_at' => $expiresAt, 'expires_at' => $retainedUntil,
                    'created_at' => $now, 'updated_at' => $now,
                ])->execute();
                $recordId = (int) $this->db->getLastInsertID();
                $transaction->commit();
                return ['recordId' => $recordId];
            } catch (\Throwable $error) {
                if ($transaction->isActive) {
                    $transaction->rollBack();
                }
                throw $error;
            }
        } finally {
            $this->db->createCommand('SELECT RELEASE_LOCK(:name)', [':name' => $lockName])->queryScalar();
        }
    }

    private function operationSuffix(string $route): string
    {
        foreach (['/analyze', '/draft'] as $suffix) {
            if (str_ends_with($route, $suffix)) {
                return $suffix;
            }
        }
        return $route;
    }

    private function assertCapacity(int $actorId, string $route, string $now): void
    {
        $activeGlobal = (int) $this->db->createCommand(
            "SELECT COUNT(*) FROM {{%ai_idempotency_requests}} WHERE state = 'in_progress' AND lease_expires_at > :now",
            [':now' => $now],
        )->queryScalar();
        $activeUser = (int) $this->db->createCommand(
            "SELECT COUNT(*) FROM {{%ai_idempotency_requests}} WHERE state = 'in_progress' "
            . 'AND lease_expires_at > :now AND actor_id = :actor',
            [':now' => $now, ':actor' => $actorId],
        )->queryScalar();
        $activeUserOperation = (int) $this->db->createCommand(
            "SELECT COUNT(*) FROM {{%ai_idempotency_requests}} WHERE state = 'in_progress' "
            . 'AND lease_expires_at > :now AND actor_id = :actor AND route LIKE :operation',
            [':now' => $now, ':actor' => $actorId, ':operation' => '%' . $this->operationSuffix($route)],
        )->queryScalar();
        if ($activeUserOperation >= 1) {
            throw new IdempotencyConflict('Такая AI-операция у вас уже выполняется. Дождитесь её завершения.');
        }
        if ($activeUser >= $this->perUserLimit) {
            throw new IdempotencyConflict('У вас уже выполняются две AI-операции. Дождитесь их завершения.');
        }
        if ($activeGlobal >= $this->globalLimit) {
            throw new IdempotencyConflict('ЛИЗА сейчас занята. Повторите попытку позже.');
        }
    }

    private function finalize(int $recordId, mixed $body, int $statusCode, ?string $location): void
    {
        $responseJson = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $transaction = $this->db->beginTransaction();
        try {
            $this->db->createCommand()->update('{{%ai_idempotency_requests}}', [
                'state' => 'completed', 'status_code' => $statusCode, 'response_json' => $responseJson,
                'location' => $location, 'updated_at' => Clock::now(),
            ], ['id' => $recordId, 'state' => 'in_progress'])->execute();
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $error;
        }
    }
}

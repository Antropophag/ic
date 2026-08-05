<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Infrastructure\Http\IdempotencyConflict;
use App\Infrastructure\Http\IdempotencyStore;
use Tests\Integration\IntegrationTestCase;

final class IdempotencyStoreTest extends IntegrationTestCase
{
    public function testSuccessfulResultIsReplayedWithoutExecutingOperationTwice(): void
    {
        $actorId = $this->createUser(uniqid('idem-', true), 'Idempotency actor');
        $calls = 0;
        $store = new IdempotencyStore($this->db());
        $operation = static function () use (&$calls): array {
            ++$calls;
            return ['id' => 42];
        };

        $first = $store->execute(
            $actorId,
            'POST',
            'api/v1/requests',
            str_repeat('a', 16),
            hash('sha256', 'body'),
            $operation,
            static fn (): int => 201,
            static fn (): string => '/api/v1/requests/42'
        );
        $second = $store->execute(
            $actorId,
            'POST',
            'api/v1/requests',
            str_repeat('a', 16),
            hash('sha256', 'body'),
            $operation,
            static fn (): int => 500,
            static fn (): ?string => null
        );

        self::assertSame(1, $calls);
        self::assertFalse($first['replayed']);
        self::assertTrue($second['replayed']);
        self::assertSame($first['body'], $second['body']);
        self::assertSame(201, $second['statusCode']);
        self::assertSame('/api/v1/requests/42', $second['location']);
    }

    public function testSameKeyWithDifferentFingerprintIsRejected(): void
    {
        $actorId = $this->createUser(uniqid('idem-', true), 'Idempotency actor');
        $store = new IdempotencyStore($this->db());
        $store->execute(
            $actorId,
            'POST',
            'api/v1/requests',
            str_repeat('b', 16),
            hash('sha256', 'first'),
            static fn (): array => ['id' => 1],
            static fn (): int => 201,
            static fn (): ?string => null
        );

        $this->expectException(IdempotencyConflict::class);
        $store->execute(
            $actorId,
            'POST',
            'api/v1/requests',
            str_repeat('b', 16),
            hash('sha256', 'second'),
            static fn (): array => ['id' => 2],
            static fn (): int => 201,
            static fn (): ?string => null
        );
    }

    public function testExceptionRollsBackClaimAndAllowsRetry(): void
    {
        $actorId = $this->createUser(uniqid('idem-', true), 'Idempotency actor');
        $store = new IdempotencyStore($this->db());
        try {
            $store->execute(
                $actorId,
                'POST',
                'api/v1/requests',
                str_repeat('c', 16),
                hash('sha256', 'body'),
                static fn (): array => throw new \RuntimeException('simulated 500'),
                static fn (): int => 500,
                static fn (): ?string => null
            );
            self::fail('Expected operation failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('simulated 500', $error->getMessage());
        }

        $retry = $store->execute(
            $actorId,
            'POST',
            'api/v1/requests',
            str_repeat('c', 16),
            hash('sha256', 'body'),
            static fn (): array => ['id' => 3],
            static fn (): int => 201,
            static fn (): ?string => null
        );
        self::assertSame(['id' => 3], $retry['body']);
        self::assertFalse($retry['replayed']);
    }

    public function testErrorResponseIsNotStored(): void
    {
        $actorId = $this->createUser(uniqid('idem-', true), 'Idempotency actor');
        $calls = 0;
        $store = new IdempotencyStore($this->db());
        $failure = function () use (&$calls, $actorId): array {
            ++$calls;
            $this->insertAudit($actorId, 'request.create_denied');
            return ['error' => true];
        };
        $store->execute(
            $actorId,
            'POST',
            'api/v1/requests',
            str_repeat('d', 16),
            hash('sha256', 'body'),
            $failure,
            static fn (): int => 500,
            static fn (): ?string => null
        );
        $store->execute(
            $actorId,
            'POST',
            'api/v1/requests',
            str_repeat('d', 16),
            hash('sha256', 'body'),
            $failure,
            static fn (): int => 500,
            static fn (): ?string => null
        );
        self::assertSame(2, $calls);
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%audit_events}} WHERE actor_id = :actor AND event_type = :type',
            [':actor' => $actorId, ':type' => 'request.create_denied'],
        ));
    }

    public function testExpiredKeyCanBeReusedAndCleanupPreservesActiveRows(): void
    {
        $actorId = $this->createUser(uniqid('idem-', true), 'Idempotency actor');
        $now = \App\Infrastructure\Clock::now();
        for ($index = 0; $index < 150; ++$index) {
            $this->insertRecord($actorId, "expired-{$index}", '2000-01-01 00:00:00.000000');
        }
        for ($index = 0; $index < 10; ++$index) {
            $this->insertRecord($actorId, "active-{$index}", '2999-01-01 00:00:00.000000');
        }
        $store = new IdempotencyStore($this->db());
        self::assertSame(100, $store->cleanupExpired());
        self::assertSame(50, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%idempotency_requests}} WHERE expires_at < :now',
            [':now' => $now],
        ));
        self::assertSame(10, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%idempotency_requests}} WHERE expires_at > :now',
            [':now' => $now],
        ));

        $this->db()->createCommand()->insert('{{%idempotency_requests}}', [
            'actor_id' => $actorId,
            'http_method' => 'POST',
            'route' => 'api/v1/requests',
            'key_hash' => hash('sha256', str_repeat('e', 16)),
            'request_hash' => hash('sha256', 'old-body'),
            'status_code' => 201,
            'response_json' => '{"id":1}',
            'created_at' => '2000-01-01 00:00:00.000000',
            'expires_at' => '2000-01-02 00:00:00.000000',
        ])->execute();
        $retry = $store->execute(
            $actorId,
            'POST',
            'api/v1/requests',
            str_repeat('e', 16),
            hash('sha256', 'new-body'),
            static fn (): array => ['id' => 99],
            static fn (): int => 201,
            static fn (): ?string => null,
        );
        self::assertFalse($retry['replayed']);
    }

    public function testNullBodyAndResponseSizeLimit(): void
    {
        $actorId = $this->createUser(uniqid('idem-', true), 'Idempotency actor');
        $store = new IdempotencyStore($this->db());
        $result = $store->execute(
            $actorId,
            'POST',
            'api/v1/empty',
            str_repeat('f', 16),
            hash('sha256', 'empty'),
            static fn (): null => null,
            static fn (): int => 204,
            static fn (): ?string => null,
        );
        self::assertNull($result['body']);
        self::assertNull($store->execute(
            $actorId,
            'POST',
            'api/v1/empty',
            str_repeat('f', 16),
            hash('sha256', 'empty'),
            static fn (): array => ['unexpected'],
            static fn (): int => 500,
            static fn (): ?string => null,
        )['body']);

        $this->expectException(\LengthException::class);
        $store->execute(
            $actorId,
            'POST',
            'api/v1/large',
            str_repeat('g', 16),
            hash('sha256', 'large'),
            static fn (): array => ['value' => str_repeat('x', IdempotencyStore::MAX_RESPONSE_BYTES)],
            static fn (): int => 200,
            static fn (): ?string => null,
        );
    }

    public function testDeniedAuditCommitsButClaimIsReleasedForControlledClientError(): void
    {
        $actorId = $this->createUser(uniqid('idem-', true), 'Idempotency actor');
        $store = new IdempotencyStore($this->db());
        try {
            $store->execute(
                $actorId,
                'POST',
                'api/v1/requests',
                str_repeat('h', 16),
                hash('sha256', 'denied'),
                function () use ($actorId): never {
                    $this->insertAudit($actorId, 'request.create_denied');
                    throw new \yii\web\ForbiddenHttpException('denied');
                },
                static fn (): int => 403,
                static fn (): ?string => null,
            );
            self::fail('Expected forbidden response.');
        } catch (\yii\web\ForbiddenHttpException) {
            self::assertSame(1, (int) $this->scalar(
                'SELECT COUNT(*) FROM {{%audit_events}} WHERE actor_id = :actor AND event_type = :type',
                [':actor' => $actorId, ':type' => 'request.create_denied'],
            ));
            self::assertSame(0, (int) $this->scalar(
                'SELECT COUNT(*) FROM {{%idempotency_requests}} WHERE actor_id = :actor',
                [':actor' => $actorId],
            ));
        }
    }

    public function testReturnedClientErrorCommitsDeniedAuditButReleasesClaim(): void
    {
        $actorId = $this->createUser(uniqid('idem-', true), 'Idempotency actor');
        $result = (new IdempotencyStore($this->db()))->execute(
            $actorId,
            'POST',
            'api/v1/requests/report',
            str_repeat('j', 16),
            hash('sha256', 'returned-denial'),
            function () use ($actorId): array {
                $this->insertAudit($actorId, 'request.report_upload_denied');
                return ['errors' => ['file' => ['invalid']]];
            },
            static fn (): int => 422,
            static fn (): ?string => null,
        );

        self::assertSame(422, $result['statusCode']);
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%audit_events}} WHERE actor_id = :actor AND event_type = :type',
            [':actor' => $actorId, ':type' => 'request.report_upload_denied'],
        ));
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%idempotency_requests}} WHERE actor_id = :actor',
            [':actor' => $actorId],
        ));
    }

    public function testServerErrorRollsBackAuditAndClaim(): void
    {
        $actorId = $this->createUser(uniqid('idem-', true), 'Idempotency actor');
        $store = new IdempotencyStore($this->db());
        try {
            $store->execute(
                $actorId,
                'POST',
                'api/v1/requests',
                str_repeat('i', 16),
                hash('sha256', 'failed'),
                function () use ($actorId): never {
                    $this->insertAudit($actorId, 'request.create_denied');
                    throw new \yii\web\ServerErrorHttpException('failed');
                },
                static fn (): int => 500,
                static fn (): ?string => null,
            );
            self::fail('Expected server error.');
        } catch (\yii\web\ServerErrorHttpException) {
            self::assertSame(0, (int) $this->scalar(
                'SELECT COUNT(*) FROM {{%audit_events}} WHERE actor_id = :actor AND event_type = :type',
                [':actor' => $actorId, ':type' => 'request.create_denied'],
            ));
            self::assertSame(0, (int) $this->scalar(
                'SELECT COUNT(*) FROM {{%idempotency_requests}} WHERE actor_id = :actor',
                [':actor' => $actorId],
            ));
        }
    }

    private function insertRecord(int $actorId, string $key, string $expiresAt): void
    {
        $this->db()->createCommand()->insert('{{%idempotency_requests}}', [
            'actor_id' => $actorId,
            'http_method' => 'POST',
            'route' => 'api/v1/cleanup/' . $key,
            'key_hash' => hash('sha256', $key),
            'request_hash' => hash('sha256', 'body'),
            'status_code' => 200,
            'response_json' => '{}',
            'created_at' => '2000-01-01 00:00:00.000000',
            'expires_at' => $expiresAt,
        ])->execute();
    }

    private function insertAudit(int $actorId, string $eventType): void
    {
        $this->db()->createCommand()->insert('{{%audit_events}}', [
            'event_type' => $eventType,
            'entity_type' => 'request_creation',
            'entity_id' => $actorId,
            'actor_id' => $actorId,
            'rule_id' => 'IDEM-001',
            'payload_json' => '{}',
            'created_at' => \App\Infrastructure\Clock::now(),
        ])->execute();
    }
}

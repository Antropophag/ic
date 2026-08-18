<?php

declare(strict_types=1);

namespace Tests\Integration\Ai;

use App\Application\Ai\AiFeatureUnavailable;
use App\Infrastructure\Ai\AiFileCleanupProcessor;
use App\Infrastructure\Ai\DatabaseAiFileCleanupQueue;
use App\Infrastructure\Clock;
use App\Infrastructure\Http\AiIdempotencyStore;
use App\Infrastructure\Http\IdempotencyConflict;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class AiRequestLifecycleTest extends TestCase
{
    private Connection $db;
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->db = new Connection([
            'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'ic_test'),
            'username' => getenv('DB_USER') ?: 'ic', 'password' => getenv('DB_PASSWORD') ?: '', 'charset' => 'utf8mb4',
        ]);
        $this->db->open();
    }

    protected function tearDown(): void
    {
        $this->db->createCommand()->delete(
            '{{%ai_file_cleanup}}',
            ['external_file_id' => 'external-file-42'],
        )->execute();
        if ($this->userIds !== []) {
            $this->db->createCommand()->delete(
                '{{%ai_idempotency_requests}}',
                ['actor_id' => $this->userIds],
            )->execute();
            $this->db->createCommand()->delete('{{%users}}', ['id' => $this->userIds])->execute();
        }
        $this->db->close();
    }

    public function testExternalOperationRunsWithoutTransactionAndCompletedResultReplays(): void
    {
        $actor = $this->user();
        $store = new AiIdempotencyStore($this->db, 1, 4, 30);
        $calls = 0;
        $operation = function () use (&$calls): array {
            ++$calls;
            self::assertNull($this->db->getTransaction());
            return ['status' => 'completed'];
        };
        $first = $store->execute($actor, 'POST', 'ai/analyze', str_repeat('a', 16), 'hash', $operation, static fn (): int => 200, static fn (): ?string => null);
        $second = $store->execute($actor, 'POST', 'ai/analyze', str_repeat('a', 16), 'hash', $operation, static fn (): int => 200, static fn (): ?string => null);

        self::assertFalse($first['replayed']);
        self::assertTrue($second['replayed']);
        self::assertSame(1, $calls);
    }

    public function testAnalysisAndDraftCanOverlapButThirdUserOperationIsRejected(): void
    {
        $firstActor = $this->user();
        $this->inProgress($firstActor, 'analyze');
        $store = new AiIdempotencyStore($this->db, 2, 4, 30);
        $draftRan = false;
        $store->execute(
            $firstActor,
            'POST',
            'ai/draft',
            str_repeat('b', 16),
            'hash-b',
            static function () use (&$draftRan): array {
                $draftRan = true;
                return [];
            },
            static fn (): int => 200,
            static fn (): ?string => null,
        );
        self::assertTrue($draftRan);

        $this->inProgress($firstActor, 'draft');
        try {
            $store->execute($firstActor, 'POST', 'ai/analyze', str_repeat('c', 16), 'hash-c', static fn (): array => [], static fn (): int => 200, static fn (): ?string => null);
            self::fail('Third concurrent operation must be rejected.');
        } catch (IdempotencyConflict $error) {
            self::assertStringContainsString('уже выполняется', mb_strtolower($error->getMessage()));
        }
    }

    public function testSecondOperationOfSameTypeIsRejectedBeforeUserLimit(): void
    {
        $actor = $this->user();
        $this->inProgress($actor, 'analyze');
        $store = new AiIdempotencyStore($this->db, 2, 4, 30);

        $this->expectException(IdempotencyConflict::class);
        $store->execute(
            $actor,
            'POST',
            'requests/99/ai/technical-specification/analyze',
            str_repeat('s', 16),
            'hash-same-type',
            static fn (): array => [],
            static fn (): int => 200,
            static fn (): ?string => null,
        );
    }

    public function testUnknownRouteUsesExactMatchInsteadOfSuffixCollision(): void
    {
        $actor = $this->user();
        $this->inProgress($actor, 'prefix/custom_operation');
        $ran = false;

        (new AiIdempotencyStore($this->db, 2, 4, 30))->execute(
            $actor,
            'POST',
            'custom_operation',
            str_repeat('u', 16),
            'hash-unknown-route',
            static function () use (&$ran): array {
                $ran = true;
                return [];
            },
            static fn (): int => 200,
            static fn (): ?string => null,
        );

        self::assertTrue($ran);
    }

    public function testTwoParallelPairsAreAllowedAndFifthOperationIsRejected(): void
    {
        $firstActor = $this->user();
        $secondActor = $this->user();
        $this->inProgress($firstActor, 'analyze');
        $this->inProgress($firstActor, 'draft');
        $this->inProgress($secondActor, 'analyze');
        $store = new AiIdempotencyStore($this->db, 2, 4, 30);
        $fourthRan = false;
        $store->execute(
            $secondActor,
            'POST',
            'ai/draft',
            str_repeat('p', 16),
            'hash-pair-draft',
            static function () use (&$fourthRan): array {
                $fourthRan = true;
                return [];
            },
            static fn (): int => 200,
            static fn (): ?string => null,
        );
        self::assertTrue($fourthRan);

        $this->inProgress($secondActor, 'draft');
        $this->expectException(IdempotencyConflict::class);
        $store->execute($this->user(), 'POST', 'ai/analyze', str_repeat('g', 16), 'hash-g', static fn (): array => [], static fn (): int => 200, static fn (): ?string => null);
    }

    public function testGlobalSlotIsReleasedAfterCompletionErrorAndTimeout(): void
    {
        foreach ([$this->user(), $this->user(), $this->user()] as $index => $actor) {
            $this->inProgress($actor, $index % 2 === 0 ? 'analyze' : 'draft');
        }
        $store = new AiIdempotencyStore($this->db, 2, 4, 30);
        $outcomes = [
            null,
            new \RuntimeException('upstream error'),
            new AiFeatureUnavailable('operation timeout'),
        ];

        foreach ($outcomes as $index => $outcome) {
            try {
                $store->execute(
                    $this->user(),
                    'POST',
                    'ai/outcome-' . $index,
                    str_repeat((string) ($index + 1), 16),
                    'hash-outcome-' . $index,
                    static function () use ($outcome): array {
                        if ($outcome !== null) {
                            throw $outcome;
                        }
                        return ['status' => 'completed'];
                    },
                    static fn (): int => 200,
                    static fn (): ?string => null,
                );
            } catch (\Throwable $error) {
                self::assertSame($outcome, $error);
            }

            $probeRan = false;
            $store->execute(
                $this->user(),
                'POST',
                'ai/probe-' . $index,
                str_repeat((string) ($index + 4), 16),
                'hash-probe-' . $index,
                static function () use (&$probeRan): array {
                    $probeRan = true;
                    return [];
                },
                static fn (): int => 200,
                static fn (): ?string => null,
            );
            self::assertTrue($probeRan);
        }
    }

    public function testFailureReplaysForSameKeyButNewExplicitIntentExecutesAgain(): void
    {
        $actor = $this->user();
        $store = new AiIdempotencyStore($this->db, 1, 1, 30);
        $calls = 0;
        try {
            $store->execute(
                $actor,
                'POST',
                'ai/analyze',
                str_repeat('d', 16),
                'hash-d',
                static function () use (&$calls): never {
                    ++$calls;
                    throw new \RuntimeException('secret upstream failure');
                },
                static fn (): int => 200,
                static fn (): ?string => null,
            );
            self::fail('Initial failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('secret upstream failure', $error->getMessage());
        }
        $replay = $store->execute(
            $actor,
            'POST',
            'ai/analyze',
            str_repeat('d', 16),
            'hash-d',
            static function () use (&$calls): array {
                ++$calls;
                return [];
            },
            static fn (): int => 200,
            static fn (): ?string => null,
        );

        self::assertSame(1, $calls);
        self::assertTrue($replay['replayed']);
        self::assertSame(503, $replay['statusCode']);
        self::assertStringNotContainsString('secret', json_encode($replay['body'], JSON_THROW_ON_ERROR));

        $retry = $store->execute(
            $actor,
            'POST',
            'ai/analyze',
            str_repeat('r', 16),
            'hash-d',
            static function () use (&$calls): array {
                ++$calls;
                return ['status' => 'completed'];
            },
            static fn (): int => 200,
            static fn (): ?string => null,
        );
        self::assertSame(2, $calls);
        self::assertFalse($retry['replayed']);
        self::assertSame(['status' => 'completed'], $retry['body']);
    }

    public function testCleanupFailurePersistsOnlyIdentifierAndRetryEventuallyDeletesRecord(): void
    {
        $actor = $this->user();
        (new AiIdempotencyStore($this->db, 1, 1, 30))->execute(
            $actor,
            'POST',
            'ai/expired',
            str_repeat('e', 16),
            'hash-e',
            static fn (): array => ['result' => 'temporary replay'],
            static fn (): int => 200,
            static fn (): ?string => null,
        );
        $this->db->createCommand()->update(
            '{{%ai_idempotency_requests}}',
            ['expires_at' => Clock::now()],
            ['actor_id' => $actor],
        )->execute();
        $queue = new DatabaseAiFileCleanupQueue($this->db);
        $queue->schedule('external-file-42', new \RuntimeException('secret token and document content'));
        $row = $this->db->createCommand('SELECT * FROM {{%ai_file_cleanup}} WHERE external_file_id = :id', [':id' => 'external-file-42'])->queryOne();
        self::assertIsArray($row);
        self::assertSame(\RuntimeException::class, $row['last_error_class']);
        self::assertStringNotContainsString('secret', json_encode($row, JSON_THROW_ON_ERROR));

        $this->db->createCommand()->update(
            '{{%ai_file_cleanup}}',
            ['attempts' => 99],
            ['external_file_id' => 'external-file-42'],
        )->execute();
        $queue->schedule('external-file-42', new \RuntimeException('retry'));
        self::assertSame(0, (int) $this->db->createCommand(
            'SELECT attempts FROM {{%ai_file_cleanup}} WHERE external_file_id = :id',
            [':id' => 'external-file-42'],
        )->queryScalar());

        $transport = new CleanupTransport();
        $transport->failDelete = true;
        $processor = new AiFileCleanupProcessor($this->db, $transport);
        $failed = $processor->processAvailableBatch();
        self::assertSame(1, $failed['failed']);
        self::assertSame(1, $failed['expired']);
        self::assertSame(0, (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%ai_idempotency_requests}} WHERE actor_id = :actor',
            [':actor' => $actor],
        )->queryScalar());
        $this->db->createCommand()->update('{{%ai_file_cleanup}}', ['next_attempt_at' => Clock::now()], ['external_file_id' => 'external-file-42'])->execute();
        $transport->failDelete = false;
        self::assertSame(1, $processor->processAvailableBatch()['deleted']);
        self::assertSame(0, (int) $this->db->createCommand('SELECT COUNT(*) FROM {{%ai_file_cleanup}} WHERE external_file_id = :id', [':id' => 'external-file-42'])->queryScalar());
    }

    private function user(): int
    {
        $now = Clock::now();
        $this->db->createCommand()->insert('{{%users}}', [
            'ad_login' => uniqid('ai.lifecycle.', true), 'display_name' => 'AI Lifecycle',
            'email' => uniqid('ai.', true) . '@example.invalid', 'department' => 'Tests',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ])->execute();
        return $this->userIds[] = (int) $this->db->getLastInsertID();
    }

    private function inProgress(int $actorId, string $suffix): void
    {
        $now = Clock::now();
        $future = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d H:i:s.u');
        $this->db->createCommand()->insert('{{%ai_idempotency_requests}}', [
            'actor_id' => $actorId, 'http_method' => 'POST', 'route' => 'ai/' . $suffix,
            'key_hash' => hash('sha256', $suffix), 'request_hash' => hash('sha256', 'request-' . $suffix),
            'state' => 'in_progress', 'lease_expires_at' => $future, 'expires_at' => $future,
            'created_at' => $now, 'updated_at' => $now,
        ])->execute();
    }
}

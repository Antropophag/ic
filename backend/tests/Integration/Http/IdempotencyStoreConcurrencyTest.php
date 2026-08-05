<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class IdempotencyStoreConcurrencyTest extends TestCase
{
    public function testConcurrentSamePayloadExecutesOperationOnceAndReplaysAfterWaiting(): void
    {
        $result = $this->runConcurrent(hash('sha256', 'same'), hash('sha256', 'same'));

        self::assertSame(['ok', 'ok'], array_column($result, 'outcome'));
        self::assertSame([false, true], array_column($result, 'replayed'));
        self::assertGreaterThan(0.5, $result[1]['elapsed']);
        self::assertSame(1, $result['operationCount']);
        self::assertFalse($result['secondOperationStarted']);
    }

    public function testConcurrentDifferentPayloadRejectsSecondWithoutExecutingIt(): void
    {
        $result = $this->runConcurrent(hash('sha256', 'first'), hash('sha256', 'second'));

        self::assertSame('ok', $result[0]['outcome']);
        self::assertFalse($result[0]['replayed']);
        self::assertSame('conflict', $result[1]['outcome']);
        self::assertGreaterThan(0.5, $result[1]['elapsed']);
        self::assertSame(1, $result['operationCount']);
        self::assertFalse($result['secondOperationStarted']);
    }

    public function testSimultaneousSamePayloadClaimsDoNotExposeDatabaseErrors(): void
    {
        $result = $this->runConcurrent(hash('sha256', 'same'), hash('sha256', 'same'), false);

        self::assertSame(['ok', 'ok'], array_column($result, 'outcome'));
        $replayed = array_column($result, 'replayed');
        sort($replayed);
        self::assertSame([false, true], $replayed);
        self::assertSame(1, $result['operationCount']);
        self::assertSame(1, $result['operationsStarted']);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, operationCount: int, operationsStarted: int, secondOperationStarted: bool} */
    private function runConcurrent(string $firstHash, string $secondHash, bool $stageSecond = true): array
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the concurrency contract test.');
        }
        $db = $this->connection();
        $login = uniqid('idem-concurrent-', true);
        $now = gmdate('Y-m-d H:i:s');
        $db->createCommand()->insert('{{%users}}', [
            'ad_login' => $login,
            'display_name' => 'Concurrent actor',
            'email' => $login . '@example.invalid',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        $actorId = (int) $db->getLastInsertID();
        $key = str_repeat('p', 16);
        $startFile = tempnam(sys_get_temp_dir(), 'idem-start-');
        $firstEntered = tempnam(sys_get_temp_dir(), 'idem-entered-');
        $secondEntered = tempnam(sys_get_temp_dir(), 'idem-entered-');
        $resultFiles = [tempnam(sys_get_temp_dir(), 'idem-result-'), tempnam(sys_get_temp_dir(), 'idem-result-')];
        foreach ([$startFile, $firstEntered, $secondEntered, ...$resultFiles] as $file) {
            self::assertIsString($file);
            self::assertTrue(unlink($file));
        }
        $processes = [];
        $pipes = [[], []];
        try {
            $processes[0] = $this->startWorker(
                $actorId,
                $key,
                $firstHash,
                'first',
                $startFile,
                $firstEntered,
                $resultFiles[0],
                1_000_000,
                $pipes[0],
            );
            if ($stageSecond) {
                touch($startFile);
                $this->waitForFile($firstEntered);
            }
            $processes[1] = $this->startWorker(
                $actorId,
                $key,
                $secondHash,
                'second',
                $startFile,
                $secondEntered,
                $resultFiles[1],
                0,
                $pipes[1],
            );
            if (!$stageSecond) {
                touch($startFile);
            }
            foreach ($processes as $index => $process) {
                $output = '';
                foreach ($pipes[$index] as $pipe) {
                    $output .= (string) stream_get_contents($pipe);
                    fclose($pipe);
                }
                self::assertSame(0, proc_close($process), $output);
            }
            $results = array_map(
                static fn (string $file): array => json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR),
                $resultFiles,
            );
            $operationCount = (int) $db->createCommand(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE actor_id = :actor AND event_type LIKE 'idempotency.concurrent.%'",
                [':actor' => $actorId],
            )->queryScalar();
            return [
                $results[0],
                $results[1],
                'operationCount' => $operationCount,
                'operationsStarted' => (int) file_exists($firstEntered) + (int) file_exists($secondEntered),
                'secondOperationStarted' => file_exists($secondEntered),
            ];
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            $db->createCommand()->delete('{{%audit_events}}', ['actor_id' => $actorId])->execute();
            $db->createCommand()->delete('{{%users}}', ['id' => $actorId])->execute();
            $db->close();
            foreach ([$startFile, $firstEntered, $secondEntered, ...$resultFiles] as $file) {
                @unlink($file);
            }
        }
    }

    /** @param array<int, resource> $pipes */
    private function startWorker(
        int $actorId,
        string $key,
        string $requestHash,
        string $label,
        string $startFile,
        string $enteredFile,
        string $resultFile,
        int $sleepMicroseconds,
        array &$pipes,
    ): mixed {
        $worker = <<<'PHP'
            require getcwd() . '/vendor/autoload.php';
            require getcwd() . '/vendor/yiisoft/yii2/Yii.php';
            $db = new \yii\db\Connection([
                'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'ic_test'),
                'username' => getenv('DB_USER') ?: 'ic', 'password' => getenv('DB_PASSWORD') ?: '', 'charset' => 'utf8mb4',
            ]);
            while (!file_exists($argv[5])) { usleep(1000); }
            $started = microtime(true);
            try {
                $result = (new \App\Infrastructure\Http\IdempotencyStore($db))->execute(
                    (int) $argv[1], 'POST', 'api/v1/requests', $argv[2], $argv[3],
                    function () use ($db, $argv): array {
                        touch($argv[6]);
                        $db->createCommand()->insert('{{%audit_events}}', [
                            'event_type' => 'idempotency.concurrent.' . $argv[4], 'entity_type' => 'test',
                            'entity_id' => (int) $argv[1], 'actor_id' => (int) $argv[1], 'rule_id' => 'IDEM-001',
                            'payload_json' => '{}', 'created_at' => gmdate('Y-m-d H:i:s'),
                        ])->execute();
                        usleep((int) $argv[8]);
                        return ['winner' => $argv[4]];
                    },
                    static fn (): int => 201, static fn (): ?string => null,
                );
                $output = ['outcome' => 'ok', 'replayed' => $result['replayed'], 'elapsed' => microtime(true) - $started];
            } catch (\App\Infrastructure\Http\IdempotencyConflict) {
                $output = ['outcome' => 'conflict', 'elapsed' => microtime(true) - $started];
            } catch (\Throwable $error) {
                $output = ['outcome' => 'error', 'class' => $error::class, 'message' => $error->getMessage(), 'elapsed' => microtime(true) - $started];
            }
            file_put_contents($argv[7], json_encode($output, JSON_THROW_ON_ERROR));
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $worker, (string) $actorId, $key, $requestHash, $label, $startFile,
                $enteredFile, $resultFile, (string) $sleepMicroseconds],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        return $process;
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 10.0;
        while (!file_exists($path)) {
            if (microtime(true) > $deadline) {
                self::fail('First concurrent operation did not reach the barrier.');
            }
            usleep(1_000);
        }
    }

    private function connection(): Connection
    {
        $connection = new Connection([
            'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'ic_test'),
            'username' => getenv('DB_USER') ?: 'ic',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ]);
        $connection->open();
        return $connection;
    }
}

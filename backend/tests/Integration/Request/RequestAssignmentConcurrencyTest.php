<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Http\Request\CreateRequest as CreateRequestInput;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class RequestAssignmentConcurrencyTest extends TestCase
{
    public function testTwoAssignmentsFromTheSameVersionProduceOneWinnerAndConsistentAudit(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the concurrency contract test.');
        }
        $db = $this->connection();
        $suffix = bin2hex(random_bytes(5));
        $users = [];
        $requestId = null;
        $files = [];
        $processes = [];
        $pipes = [];
        try {
            foreach (['initiator', 'manager', 'executor-a', 'executor-b'] as $kind) {
                $users[$kind] = $this->createUser($db, "assignment-{$kind}-{$suffix}", $kind);
            }
            $this->grantRole($db, $users['manager'], 'ic_manager');
            $this->grantRole($db, $users['executor-a'], 'ic_executor');
            $this->grantRole($db, $users['executor-b'], 'ic_executor');
            $input = new CreateRequestInput();
            $input->productName = "Concurrent assignment {$suffix}";
            $input->manufacturer = 'Test manufacturer';
            $input->supplier = 'Test supplier';
            $input->sampleQuantity = 1;
            $input->testMethod = 'Controlled two-session assignment';
            $request = (new \App\Application\Request\UseCase\CreateRequest(new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($db)))->execute($input->toCommand($users['initiator']))->toArray();
            $requestId = (int) $request['id'];
            $version = (int) $request['lock_version'];

            // Hold the request row until both independent sessions are ready.
            // Their SELECT ... FOR UPDATE calls then queue behind this lock, so
            // releasing it creates actual lock contention instead of relying on
            // process scheduling to overlap two otherwise serial calls.
            $barrier = $db->beginTransaction();
            $db->createCommand(
                'SELECT id FROM {{%requests}} WHERE id = :id FOR UPDATE',
                [':id' => $requestId],
            )->queryScalar();

            foreach (['start', 'ready-a', 'ready-b', 'result-a', 'result-b'] as $name) {
                $files[$name] = tempnam(sys_get_temp_dir(), "assign-{$name}-");
                self::assertIsString($files[$name]);
                self::assertTrue(unlink($files[$name]));
            }
            foreach (['a', 'b'] as $index => $label) {
                $pipeSet = [];
                $process = proc_open([
                    PHP_BINARY, '-r', self::worker(), (string) $requestId,
                    (string) $users["executor-{$label}"], (string) $version, (string) $users['manager'],
                    $files["ready-{$label}"], $files['start'], $files["result-{$label}"],
                ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipeSet, dirname(__DIR__, 3));
                self::assertIsResource($process);
                $processes[$index] = $process;
                $pipes[$index] = $pipeSet;
            }
            $this->waitForFiles([$files['ready-a'], $files['ready-b']], 10.0);
            $workerConnectionIds = [
                (int) file_get_contents($files['ready-a']),
                (int) file_get_contents($files['ready-b']),
            ];
            touch($files['start']);
            $this->waitForBlockedAssignmentQueries($db, $workerConnectionIds, 10.0);
            $barrier->rollBack();
            $this->waitForFiles([$files['result-a'], $files['result-b']], 15.0);

            $results = [];
            foreach ($processes as $index => $process) {
                $output = '';
                foreach ($pipes[$index] as $pipe) {
                    $output .= (string) stream_get_contents($pipe);
                    fclose($pipe);
                }
                self::assertSame(0, proc_close($process), $output);
                unset($processes[$index]);
                $results[] = json_decode((string) file_get_contents($files['result-' . ($index === 0 ? 'a' : 'b')]), true, 512, JSON_THROW_ON_ERROR);
            }
            $outcomes = array_column($results, 'outcome');
            sort($outcomes);
            self::assertSame(['conflict', 'ok'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));
            $winner = (int) $results[array_search('ok', array_column($results, 'outcome'), true)]['executor'];
            self::assertSame($version + 1, (int) $db->createCommand('SELECT lock_version FROM {{%requests}} WHERE id = :id', [':id' => $requestId])->queryScalar());
            self::assertSame([$winner], array_map('intval', $db->createCommand(
                "SELECT user_id FROM {{%request_assignments}} WHERE request_id = :id AND assignment_type = 'executor' AND valid_to IS NULL",
                [':id' => $requestId],
            )->queryColumn()));
            self::assertSame(1, (int) $db->createCommand(
                "SELECT COUNT(*) FROM {{%request_assignments}} WHERE request_id = :id AND assignment_type = 'executor'",
                [':id' => $requestId],
            )->queryScalar());
            $audits = $db->createCommand(
                "SELECT payload_json FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.executor_assigned'",
                [':id' => $requestId],
            )->queryColumn();
            self::assertCount(1, $audits);
            $payload = is_array($audits[0]) ? $audits[0] : json_decode((string) $audits[0], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($winner, (int) $payload['executor_id']);
        } finally {
            $transaction = $db->getTransaction();
            if ($transaction?->getIsActive()) {
                $transaction->rollBack();
            }
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            if ($requestId !== null) {
                $db->createCommand()->delete('{{%audit_events}}', ['entity_id' => $requestId, 'entity_type' => 'request'])->execute();
                $db->createCommand()->delete('{{%request_assignments}}', ['request_id' => $requestId])->execute();
                $db->createCommand()->delete('{{%requests}}', ['id' => $requestId])->execute();
            }
            foreach (array_values($users) as $userId) {
                $db->createCommand()->delete('{{%user_roles}}', ['user_id' => $userId])->execute();
                $db->createCommand()->delete('{{%users}}', ['id' => $userId])->execute();
            }
            $db->close();
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    private static function worker(): string
    {
        return <<<'PHP'
            require getcwd() . '/vendor/autoload.php';
            require getcwd() . '/vendor/yiisoft/yii2/Yii.php';
            $db = new \yii\db\Connection(['dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'ic_test'), 'username' => getenv('DB_USER') ?: 'ic', 'password' => getenv('DB_PASSWORD') ?: '', 'charset' => 'utf8mb4']);
            $db->open();
            $db->createCommand('SET SESSION innodb_lock_wait_timeout = 5')->execute();
            file_put_contents($argv[5], (string) $db->createCommand('SELECT CONNECTION_ID()')->queryScalar());
            $deadline = microtime(true) + 10.0;
            while (!file_exists($argv[6])) { if (microtime(true) >= $deadline) { exit(2); } usleep(1000); }
            try {
                (new \App\Application\Request\UseCase\AssignExecutor(new \App\Infrastructure\Persistence\Request\ExecutorAssignmentPersistenceAdapter($db)))->execute(new \App\Application\Request\Command\AssignExecutorCommand((int) $argv[1], (int) $argv[2], (int) $argv[3], (int) $argv[4]));
                $result = ['outcome' => 'ok', 'executor' => (int) $argv[2]];
            } catch (\App\Domain\Request\ConcurrentRequestModification) {
                $result = ['outcome' => 'conflict', 'executor' => (int) $argv[2]];
            } catch (\Throwable $error) {
                $result = ['outcome' => 'error', 'class' => $error::class, 'message' => $error->getMessage()];
            } finally { $db->close(); }
            file_put_contents($argv[7], json_encode($result, JSON_THROW_ON_ERROR));
            PHP;
    }

    /** @param list<int> $connectionIds */
    private function waitForBlockedAssignmentQueries(Connection $db, array $connectionIds, float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            $active = [];
            foreach ($db->createCommand('SHOW FULL PROCESSLIST')->queryAll() as $process) {
                $connectionId = (int) ($process['Id'] ?? 0);
                $info = (string) ($process['Info'] ?? '');
                if (
                    in_array($connectionId, $connectionIds, true)
                    && str_contains($info, 'SELECT status, lock_version FROM')
                ) {
                    $active[] = $connectionId;
                }
            }
            if (count(array_unique($active)) === count($connectionIds)) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('Workers did not both enter the assignment locking query before timeout');
    }

    /** @param list<string> $paths */
    private function waitForFiles(array $paths, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (array_filter($paths, static fn (string $path): bool => !file_exists($path)) !== []) {
            if (microtime(true) >= $deadline) {
                self::fail('Concurrent assignment worker exceeded its bounded deadline.');
            }
            usleep(1000);
        }
    }

    private function createUser(Connection $db, string $login, string $name): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $db->createCommand()->insert('{{%users}}', ['ad_login' => $login, 'display_name' => $name, 'email' => null, 'department' => 'Test department', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now])->execute();
        return (int) $db->getLastInsertID();
    }

    private function grantRole(Connection $db, int $userId, string $code): void
    {
        $roleId = $db->createCommand('SELECT id FROM {{%roles}} WHERE code = :code', [':code' => $code])->queryScalar();
        self::assertNotFalse($roleId, "Required role seed '{$code}' is missing.");
        $db->createCommand()->insert('{{%user_roles}}', ['user_id' => $userId, 'role_id' => (int) $roleId, 'created_at' => gmdate('Y-m-d H:i:s')])->execute();
    }

    private function connection(): Connection
    {
        $db = new Connection(['dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'ic_test'), 'username' => getenv('DB_USER') ?: 'ic', 'password' => getenv('DB_PASSWORD') ?: '', 'charset' => 'utf8mb4']);
        $db->open();
        return $db;
    }
}

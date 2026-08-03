<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Identity\AdministratorBootstrap;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class AdministratorBootstrapConcurrencyTest extends TestCase
{
    public function testConcurrentBootstrapIsIdempotent(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the concurrency contract test.');
        }

        $suffix = bin2hex(random_bytes(4));
        $sharedLogin = 'bootstrap.concurrent.shared.' . $suffix;
        $loginLists = [
            [$sharedLogin, 'bootstrap.concurrent.first.' . $suffix],
            [$sharedLogin, 'bootstrap.concurrent.second.' . $suffix],
        ];
        $startFile = tempnam(sys_get_temp_dir(), 'bootstrap-start-');
        $resultFiles = [
            tempnam(sys_get_temp_dir(), 'bootstrap-result-'),
            tempnam(sys_get_temp_dir(), 'bootstrap-result-'),
        ];
        self::assertIsString($startFile);
        self::assertIsString($resultFiles[0]);
        self::assertIsString($resultFiles[1]);
        self::assertTrue(unlink($startFile));

        $worker = <<<'PHP'
            $root = getcwd();
            require $root . '/vendor/autoload.php';
            require $root . '/vendor/yiisoft/yii2/Yii.php';
            $connection = new \yii\db\Connection([
                'dsn' => sprintf(
                    'mysql:host=%s;port=%s;dbname=%s',
                    getenv('DB_HOST') ?: '127.0.0.1',
                    getenv('DB_PORT') ?: '3306',
                    getenv('DB_NAME') ?: 'ic_test',
                ),
                'username' => getenv('DB_USER') ?: 'ic',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
            ]);
            $deadline = microtime(true) + 30.0;
            while (!file_exists($argv[1])) {
                if (microtime(true) >= $deadline) {
                    file_put_contents($argv[2], json_encode(['error' => 'start barrier timeout']));
                    exit(1);
                }
                usleep(1000);
            }
            try {
                $result = (new \App\Infrastructure\Identity\AdministratorBootstrap($connection))
                    ->bootstrap(json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR));
                file_put_contents($argv[2], json_encode(['result' => $result], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (\Throwable $error) {
                file_put_contents($argv[2], json_encode(['error' => $error->getMessage()]));
                exit(1);
            }
            PHP;

        $processes = [];
        $pipeSets = [];
        try {
            foreach ($resultFiles as $index => $resultFile) {
                $pipes = [];
                $process = proc_open(
                    [
                        PHP_BINARY,
                        '-r',
                        $worker,
                        $startFile,
                        $resultFile,
                        json_encode($loginLists[$index], JSON_THROW_ON_ERROR),
                    ],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    dirname(__DIR__, 3),
                );
                self::assertIsResource($process);
                $processes[] = $process;
                $pipeSets[] = $pipes;
            }
            touch($startFile);

            foreach ($processes as $index => $process) {
                $output = '';
                foreach ($pipeSets[$index] as $pipe) {
                    $output .= (string) stream_get_contents($pipe);
                    fclose($pipe);
                }
                self::assertSame(0, proc_close($process), $output . implode('', array_map(
                    static fn (string $file): string => (string) file_get_contents($file),
                    $resultFiles,
                )));
            }

            $verification = $this->connection();
            self::assertSame(3, (int) $verification->createCommand(
                'SELECT COUNT(*) FROM {{%users}} WHERE ad_login IN (:shared, :first, :second)',
                [
                    ':shared' => $loginLists[0][0],
                    ':first' => $loginLists[0][1],
                    ':second' => $loginLists[1][1],
                ],
            )->queryScalar());
            self::assertSame(6, (int) $verification->createCommand(
                'SELECT COUNT(*) FROM {{%user_roles}} ur '
                . 'JOIN {{%users}} u ON u.id = ur.user_id '
                . 'WHERE u.ad_login IN (:shared, :first, :second)',
                [
                    ':shared' => $loginLists[0][0],
                    ':first' => $loginLists[0][1],
                    ':second' => $loginLists[1][1],
                ],
            )->queryScalar());
            $verification->close();
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            $cleanup = $this->connection();
            $userIds = $cleanup->createCommand(
                'SELECT id FROM {{%users}} WHERE ad_login IN (:shared, :first, :second)',
                [
                    ':shared' => $loginLists[0][0],
                    ':first' => $loginLists[0][1],
                    ':second' => $loginLists[1][1],
                ],
            )->queryColumn();
            foreach ($userIds as $userId) {
                $cleanup->createCommand()->delete('{{%user_roles}}', ['user_id' => $userId])->execute();
                $cleanup->createCommand()->delete('{{%users}}', ['id' => $userId])->execute();
            }
            $cleanup->close();
            @unlink($startFile);
            foreach ($resultFiles as $resultFile) {
                @unlink($resultFile);
            }
        }
    }

    private function connection(): Connection
    {
        $connection = new Connection([
            'dsn' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_NAME') ?: 'ic_test',
            ),
            'username' => getenv('DB_USER') ?: 'ic',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ]);
        $connection->open();
        return $connection;
    }
}

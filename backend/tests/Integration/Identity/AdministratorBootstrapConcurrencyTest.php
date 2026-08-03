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

        $login = 'bootstrap.concurrent.' . bin2hex(random_bytes(4));
        $startFile = tempnam(sys_get_temp_dir(), 'bootstrap-start-');
        $resultFiles = [
            tempnam(sys_get_temp_dir(), 'bootstrap-result-'),
            tempnam(sys_get_temp_dir(), 'bootstrap-result-'),
        ];
        self::assertIsString($startFile);
        self::assertIsString($resultFiles[0]);
        self::assertIsString($resultFiles[1]);

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
            while (!file_exists($argv[1])) {
                usleep(1000);
            }
            try {
                $result = (new \App\Infrastructure\Identity\AdministratorBootstrap($connection))
                    ->bootstrap([$argv[3]]);
                file_put_contents($argv[2], json_encode(['result' => $result], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (\Throwable $error) {
                file_put_contents($argv[2], json_encode(['error' => $error->getMessage()]));
                exit(1);
            }
            PHP;

        $processes = [];
        try {
            foreach ($resultFiles as $resultFile) {
                $processes[] = proc_open(
                    [PHP_BINARY, '-r', $worker, $startFile, $resultFile, $login],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    dirname(__DIR__, 3),
                );
            }
            touch($startFile);

            foreach ($processes as $process) {
                self::assertIsResource($process);
                self::assertSame(0, proc_close($process), implode('', array_map(
                    static fn (string $file): string => (string) file_get_contents($file),
                    $resultFiles,
                )));
            }

            $verification = $this->connection();
            self::assertSame(1, (int) $verification->createCommand(
                'SELECT COUNT(*) FROM {{%users}} WHERE ad_login = :login',
                [':login' => $login],
            )->queryScalar());
            self::assertSame(2, (int) $verification->createCommand(
                'SELECT COUNT(*) FROM {{%user_roles}} ur JOIN {{%users}} u ON u.id = ur.user_id WHERE u.ad_login = :login',
                [':login' => $login],
            )->queryScalar());
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            $cleanup = $this->connection();
            $userId = $cleanup->createCommand(
                'SELECT id FROM {{%users}} WHERE ad_login = :login',
                [':login' => $login],
            )->queryScalar();
            if ($userId !== false) {
                $cleanup->createCommand()->delete('{{%user_roles}}', ['user_id' => $userId])->execute();
                $cleanup->createCommand()->delete('{{%users}}', ['id' => $userId])->execute();
            }
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

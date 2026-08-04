<?php

declare(strict_types=1);

namespace Tests\Integration\Identity;

use App\Infrastructure\Identity\BreakGlassAuthenticator;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;

final class BreakGlassIdentityProvisionerConcurrencyTest extends TestCase
{
    public function testConcurrentProvisioningCreatesOneTechnicalAdministrator(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the concurrency contract test.');
        }

        $startFile = tempnam(sys_get_temp_dir(), 'break-glass-start-');
        $resultFiles = [
            tempnam(sys_get_temp_dir(), 'break-glass-result-'),
            tempnam(sys_get_temp_dir(), 'break-glass-result-'),
        ];
        self::assertIsString($startFile);
        self::assertIsString($resultFiles[0]);
        self::assertIsString($resultFiles[1]);
        self::assertTrue(unlink($startFile));

        $worker = <<<'PHP'
            require getcwd() . '/vendor/autoload.php';
            require getcwd() . '/vendor/yiisoft/yii2/Yii.php';
            $db = new \yii\db\Connection([
                'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'ic_test'),
                'username' => getenv('DB_USER') ?: 'ic',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
            ]);
            $deadline = microtime(true) + 30.0;
            while (!file_exists($argv[1])) {
                if (microtime(true) >= $deadline) {
                    file_put_contents($argv[2], 'start barrier timeout');
                    exit(1);
                }
                usleep(1000);
            }
            try {
                $configuration = new \App\Infrastructure\Identity\BreakGlassConfiguration(
                    'Emergency.Admin',
                    password_hash('concurrency test password', PASSWORD_DEFAULT),
                );
                (new \App\Infrastructure\Identity\BreakGlassIdentityProvisioner($db, $configuration))->provision();
                file_put_contents($argv[2], 'ok');
                exit(0);
            } catch (\Throwable $error) {
                file_put_contents($argv[2], $error->getMessage());
                exit(1);
            }
            PHP;

        $processes = [];
        $pipeSets = [];
        try {
            $verification = $this->connection();
            self::assertSame(0, (int) $verification->createCommand(
                'SELECT COUNT(*) FROM {{%users}} WHERE ad_login = :login',
                [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
            )->queryScalar(), 'The concurrency test requires a clean technical identity.');
            $verification->close();

            foreach ($resultFiles as $resultFile) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, '-r', $worker, $startFile, $resultFile],
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
                self::assertSame(0, proc_close($process), $output . (string) file_get_contents($resultFiles[$index]));
            }

            $verification = $this->connection();
            self::assertSame(1, (int) $verification->createCommand(
                'SELECT COUNT(*) FROM {{%users}} WHERE ad_login = :login',
                [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
            )->queryScalar());
            self::assertSame(1, (int) $verification->createCommand(
                'SELECT COUNT(*) FROM {{%user_roles}} ur JOIN {{%users}} u ON u.id = ur.user_id '
                . 'JOIN {{%roles}} r ON r.id = ur.role_id '
                . 'WHERE u.ad_login = :login AND r.code = :role',
                [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN, ':role' => 'administrator'],
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
            $userId = $cleanup->createCommand(
                'SELECT id FROM {{%users}} WHERE ad_login = :login',
                [':login' => BreakGlassAuthenticator::TECHNICAL_LOGIN],
            )->queryScalar();
            if ($userId !== false) {
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

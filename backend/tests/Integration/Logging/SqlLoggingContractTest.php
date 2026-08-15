<?php

declare(strict_types=1);

namespace Tests\Integration\Logging;

use App\Infrastructure\Logging\ParameterSafeCommand;
use Tests\Integration\IntegrationTestCase;
use Yii;
use yii\db\Command;
use yii\db\Exception;
use yii\log\Logger;

final class SqlLoggingContractTest extends IntegrationTestCase
{
    private string $previousCommandClass;
    private Logger $previousLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCommandClass = $this->db()->commandClass;
        $this->previousLogger = Yii::getLogger();
        Yii::setLogger(new Logger(['flushInterval' => 1000]));
    }

    protected function tearDown(): void
    {
        $this->db()->commandClass = $this->previousCommandClass;
        Yii::setLogger($this->previousLogger);
        parent::tearDown();
    }

    public function testProductionQueryCommandProfilingAndErrorKeepTemplatesWithoutValues(): void
    {
        $secret = 'sql-log-secret-' . bin2hex(random_bytes(12));
        $this->db()->commandClass = ParameterSafeCommand::class;

        self::assertSame($secret, $this->db()->createCommand(
            'SELECT :query_secret',
            [':query_secret' => $secret],
        )->queryScalar());
        self::assertSame(0, $this->db()->createCommand(
            'UPDATE {{%users}} SET display_name = display_name WHERE ad_login = :command_secret',
            [':command_secret' => $secret],
        )->execute());

        $insert = 'INSERT INTO {{%users}} '
            . '(ad_login, display_name, is_active, created_at, updated_at) '
            . 'VALUES (:error_secret, :display_name, 1, NOW(6), NOW(6))';
        $this->db()->createCommand($insert, [
            ':error_secret' => $secret,
            ':display_name' => 'SQL logging contract',
        ])->execute();

        try {
            $this->db()->createCommand($insert, [
                ':error_secret' => $secret,
                ':display_name' => 'Duplicate SQL logging contract',
            ])->execute();
            self::fail('The duplicate unique value must fail.');
        } catch (Exception $error) {
            Yii::error($error, 'sql.logging.contract');
        }

        $log = $this->technicalLog();
        self::assertStringContainsString('SELECT :query_secret', $log);
        self::assertStringContainsString('UPDATE `users`', $log);
        self::assertStringContainsString(':command_secret', $log);
        self::assertStringContainsString('INSERT INTO `users`', $log);
        self::assertStringContainsString(':error_secret', $log);
        self::assertStringContainsString('SQLSTATE 23000, driver code 1062', $log);
        self::assertStringContainsString('[info][yii\\db\\Command::query]', $log);
        self::assertStringContainsString('[profile begin][yii\\db\\Command::query]', $log);
        self::assertStringContainsString('[profile end][yii\\db\\Command::query]', $log);
        self::assertStringContainsString('[error][sql.logging.contract]', $log);
        self::assertStringNotContainsString($secret, $log);
    }

    public function testDiagnosticYiiCommandStillIncludesBindValues(): void
    {
        $secret = 'sql-log-dev-secret-' . bin2hex(random_bytes(12));
        $this->db()->commandClass = Command::class;

        self::assertSame($secret, $this->db()->createCommand(
            'SELECT :diagnostic_secret',
            [':diagnostic_secret' => $secret],
        )->queryScalar());

        self::assertStringContainsString($secret, $this->technicalLog());
    }

    private function technicalLog(): string
    {
        $lines = [];
        foreach (Yii::getLogger()->messages as $message) {
            $lines[] = sprintf(
                '[%s][%s] %s',
                Logger::getLevelName($message[1]),
                $message[2],
                is_string($message[0]) ? $message[0] : (string) $message[0],
            );
        }

        return implode("\n", $lines);
    }
}

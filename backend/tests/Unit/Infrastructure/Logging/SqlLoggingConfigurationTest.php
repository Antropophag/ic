<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\ParameterSafeCommand;
use PHPUnit\Framework\TestCase;

final class SqlLoggingConfigurationTest extends TestCase
{
    private string|false $previousPassword;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPassword = getenv('DB_PASSWORD');
        putenv('DB_PASSWORD=configuration-test-password');
    }

    protected function tearDown(): void
    {
        $this->previousPassword === false
            ? putenv('DB_PASSWORD')
            : putenv('DB_PASSWORD=' . $this->previousPassword);
        parent::tearDown();
    }

    public function testProductionUsesParameterSafeCommand(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/common.php';

        self::assertSame(ParameterSafeCommand::class, $config['components']['db']['commandClass']);
    }
}

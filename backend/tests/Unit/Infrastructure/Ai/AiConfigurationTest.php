<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Ai;

use App\Infrastructure\Ai\NativeOpenWebUiTransport;
use App\Infrastructure\Ai\OpenWebUiTransport;
use PHPUnit\Framework\TestCase;

final class AiConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
        parent::tearDown();
    }

    public function testInvalidTimeoutsUseDefaultsAndLargeValuesAreBounded(): void
    {
        $this->setEnv('DB_PASSWORD', 'configuration-test-password');
        $this->setEnv('LIZA_TIMEOUT_SECONDS', 'invalid');
        $this->setEnv('LIZA_CONNECT_TIMEOUT_SECONDS', '-5');
        $this->setEnv('LIZA_COMPLETION_TIMEOUT_SECONDS', 'INF');

        $transport = $this->transportFromConfiguration();
        self::assertSame(45.0, $this->property($transport, 'timeoutSeconds'));
        self::assertSame(10.0, $this->property($transport, 'connectTimeoutSeconds'));
        self::assertSame(300.0, $this->property($transport, 'completionTimeoutSeconds'));

        $this->setEnv('LIZA_TIMEOUT_SECONDS', '999');
        $this->setEnv('LIZA_CONNECT_TIMEOUT_SECONDS', '999');
        $transport = $this->transportFromConfiguration();
        self::assertSame(300.0, $this->property($transport, 'timeoutSeconds'));
        self::assertSame(300.0, $this->property($transport, 'connectTimeoutSeconds'));
    }

    private function transportFromConfiguration(): NativeOpenWebUiTransport
    {
        $config = require dirname(__DIR__, 4) . '/config/common.php';
        $factory = $config['container']['definitions'][OpenWebUiTransport::class];
        $transport = $factory();
        self::assertInstanceOf(NativeOpenWebUiTransport::class, $transport);
        return $transport;
    }

    private function property(NativeOpenWebUiTransport $transport, string $name): float
    {
        $property = new \ReflectionProperty($transport, $name);
        return $property->getValue($transport);
    }

    private function setEnv(string $name, string $value): void
    {
        $this->originalEnvironment[$name] ??= getenv($name);
        putenv("{$name}={$value}");
    }
}

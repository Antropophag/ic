<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Ai;

use App\Infrastructure\Ai\ElephantOpenWebUiSocketEngine;
use App\Infrastructure\Ai\ElephantOpenWebUiSocketSessionFactory;
use PHPUnit\Framework\TestCase;

final class ElephantOpenWebUiSocketSessionFactoryTest extends TestCase
{
    public function testConfiguresDirectWebSocketAndTokenOnlyInAuthHandshake(): void
    {
        $session = (new ElephantOpenWebUiSocketSessionFactory())->create('https://example.test', 'secret-token', 3.5);
        $property = new \ReflectionProperty($session, 'engine');
        $engine = $property->getValue($session);

        self::assertInstanceOf(ElephantOpenWebUiSocketEngine::class, $engine);
        $options = $engine->getOptions();
        self::assertSame('websocket', $options->transport);
        self::assertSame(['websocket'], $options->transports);
        self::assertSame('ws/socket.io', $options->sio_path);
        self::assertSame(['token' => 'secret-token'], $options->auth);
        self::assertSame([], (array) $options->headers);
        self::assertSame(3.5, $options->timeout);
        self::assertFalse($options->persistent);
    }
}

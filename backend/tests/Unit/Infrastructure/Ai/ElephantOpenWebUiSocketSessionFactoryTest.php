<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Ai;

use App\Infrastructure\Ai\ElephantOpenWebUiSocketEngine;
use App\Infrastructure\Ai\ElephantOpenWebUiSocketSession;
use App\Infrastructure\Ai\ElephantOpenWebUiSocketSessionFactory;
use ElephantIO\Client;
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

    public function testSessionReturnsRealNamespaceSidAndDisconnectsClient(): void
    {
        $session = (new ElephantOpenWebUiSocketSessionFactory())->create('https://example.test', 'secret-token', 3.5);
        self::assertInstanceOf(ElephantOpenWebUiSocketSession::class, $session);
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('connect');
        $client->expects(self::once())->method('disconnect');
        $clientProperty = new \ReflectionProperty($session, 'client');
        $clientProperty->setValue($session, $client);
        $engineProperty = new \ReflectionProperty($session, 'engine');
        $engine = $engineProperty->getValue($session);
        $sidProperty = new \ReflectionProperty(ElephantOpenWebUiSocketEngine::class, 'namespaceSid');
        $sidProperty->setValue($engine, 'namespace-sid');

        self::assertSame('namespace-sid', $session->connect());
        $session->disconnect();
    }

    public function testSessionDrainsAlreadyQueuedEventWithoutSocketRead(): void
    {
        $session = (new ElephantOpenWebUiSocketSessionFactory())->create('https://example.test', 'secret-token', 3.5);
        $pendingProperty = new \ReflectionProperty($session, 'pendingEvents');
        $pendingProperty->setValue($session, [['type' => 'chat:completion']]);

        self::assertSame(['type' => 'chat:completion'], $session->waitForEvents(0.1));
    }
}

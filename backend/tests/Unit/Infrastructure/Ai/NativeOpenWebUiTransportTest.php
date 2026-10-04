<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Ai;

use App\Application\Ai\AiFeatureUnavailable;
use App\Infrastructure\Ai\NativeOpenWebUiTransport;
use PHPUnit\Framework\TestCase;

final class NativeOpenWebUiTransportTest extends TestCase
{
    public function testUsesNamespaceSidAndFiltersEventsForRequestedCompletion(): void
    {
        $session = new FakeOpenWebUiSocketSession('namespace-sid', [
            ['chat_id' => 'other', 'message_id' => 'message', 'data' => ['type' => 'chat:completion', 'data' => ['content' => 'Чужой ответ']]],
            ['chat_id' => 'chat', 'message_id' => 'message', 'data' => ['type' => 'status', 'data' => ['done' => true]]],
            ['data' => ['type' => 'chat:completion', 'data' => ['content' => 'Событие без идентификаторов', 'done' => true]]],
            ['chat_id' => 'chat', 'message_id' => 'message', 'data' => ['type' => 'chat:completion', 'data' => ['content' => 'Нужный ']]],
            ['chat_id' => 'chat', 'message_id' => 'message', 'data' => ['type' => 'chat:completion', 'data' => ['content' => 'ответ', 'done' => true]]],
        ]);
        $factory = new FakeOpenWebUiSocketSessionFactory($session);
        $transport = new NativeOpenWebUiTransport('https://example.test', 'secret-token', 1, 0.25, $factory);

        $transport->beginOperation();
        self::assertSame('namespace-sid', $transport->socketSessionId());
        self::assertSame('Нужный ответ', $transport->completion('chat', 'message'));
        self::assertSame(['https://example.test', 'secret-token', 0.25], $factory->arguments);
        $transport->disconnect();
        self::assertSame(1, $session->disconnects);
    }

    public function testSocketErrorBecomesControlledUnavailableErrorAndDisconnects(): void
    {
        $session = new FakeOpenWebUiSocketSession('namespace-sid', [], new \RuntimeException('socket failed'));
        $transport = new NativeOpenWebUiTransport(
            'https://example.test',
            'secret-token',
            1,
            0.25,
            new FakeOpenWebUiSocketSessionFactory($session),
        );
        $transport->beginOperation();
        $transport->socketSessionId();

        try {
            $transport->completion('chat', 'message');
            self::fail('Socket error must fail.');
        } catch (AiFeatureUnavailable $error) {
            self::assertStringNotContainsString('secret-token', $error->getMessage());
        } finally {
            $transport->disconnect();
        }
        self::assertSame(1, $session->disconnects);
    }

    public function testConnectErrorDisconnectsImmediately(): void
    {
        $session = new FakeOpenWebUiSocketSession('', [], null, new \RuntimeException('connect failed'));
        $transport = new NativeOpenWebUiTransport(
            'https://example.test',
            'secret-token',
            1,
            0.25,
            new FakeOpenWebUiSocketSessionFactory($session),
        );

        $transport->beginOperation();
        $this->expectException(AiFeatureUnavailable::class);
        try {
            $transport->socketSessionId();
        } finally {
            self::assertSame(1, $session->disconnects);
        }
    }

    public function testCompletionTimeoutIsFiniteAndDisconnectRemainsPossible(): void
    {
        $session = new FakeOpenWebUiSocketSession('namespace-sid', [], null, null, true);
        $transport = new NativeOpenWebUiTransport(
            'https://example.test',
            'secret-token',
            1,
            0.25,
            new FakeOpenWebUiSocketSessionFactory($session),
            0.01,
        );
        $transport->beginOperation();
        $transport->socketSessionId();

        try {
            $transport->completion('chat', 'message');
            self::fail('Completion timeout must fail.');
        } catch (AiFeatureUnavailable $error) {
            self::assertStringContainsString('отведённое время', $error->getMessage());
        } finally {
            $transport->disconnect();
        }
        self::assertSame(1, $session->disconnects);
    }

    public function testPersistedCompletionFinishesWhenSocketDoneEventWasMissed(): void
    {
        $session = new FakeOpenWebUiSocketSession('namespace-sid', [
            ['chat_id' => 'chat', 'message_id' => 'message', 'data' => ['type' => 'status', 'data' => ['stage' => 'rag']]],
        ]);
        $checks = 0;
        $transport = new NativeOpenWebUiTransport(
            'https://example.test',
            'secret-token',
            1,
            0.25,
            new FakeOpenWebUiSocketSessionFactory($session),
            10,
            static function (string $chatId, string $messageId, float $remaining) use (&$checks) {
                ++$checks;
                self::assertSame('chat', $chatId);
                self::assertSame('message', $messageId);
                self::assertGreaterThan(0, $remaining);
                return 'Сохранённый ответ';
            },
        );
        $transport->beginOperation();
        $transport->socketSessionId();

        self::assertSame('Сохранённый ответ', $transport->completion('chat', 'message'));
        self::assertSame(1, $checks);
    }

    public function testInactiveGenerationWaitsUntilPersistedResultAppears(): void
    {
        $session = new FakeOpenWebUiSocketSession('namespace-sid', [
            ['chat_id' => 'chat', 'message_id' => 'message', 'data' => ['type' => 'chat:active', 'data' => ['active' => false]]],
        ]);
        $checks = 0;
        $transport = new NativeOpenWebUiTransport(
            'https://example.test',
            'secret-token',
            1,
            0.25,
            new FakeOpenWebUiSocketSessionFactory($session),
            1,
            static function () use (&$checks): ?string {
                ++$checks;
                return $checks >= 2 ? 'Поздно сохранённый ответ' : null;
            },
        );
        $transport->beginOperation();
        $transport->socketSessionId();

        self::assertSame('Поздно сохранённый ответ', $transport->completion('chat', 'message'));
        self::assertGreaterThanOrEqual(2, $checks);
    }

    public function testInactiveGenerationWithoutPersistedResultFailsAtOperationDeadline(): void
    {
        $session = new FakeOpenWebUiSocketSession('namespace-sid', [
            ['chat_id' => 'chat', 'message_id' => 'message', 'data' => ['type' => 'chat:active', 'data' => ['active' => false]]],
        ], honorTimeout: true);
        $transport = new NativeOpenWebUiTransport(
            'https://example.test',
            'secret-token',
            1,
            0.25,
            new FakeOpenWebUiSocketSessionFactory($session),
            0.02,
            static fn (): ?string => null,
        );
        $transport->beginOperation();
        $transport->socketSessionId();

        $this->expectException(AiFeatureUnavailable::class);
        $this->expectExceptionMessage('завершила обработку без ответа');
        $transport->completion('chat', 'message');
    }

    public function testCompletionUsesRemainingWholeOperationBudget(): void
    {
        $session = new FakeOpenWebUiSocketSession('namespace-sid', []);
        $transport = new NativeOpenWebUiTransport(
            'https://example.test',
            'secret-token',
            1,
            0.25,
            new FakeOpenWebUiSocketSessionFactory($session),
            0.001,
        );
        $transport->beginOperation();
        usleep(2_000);

        $this->expectException(AiFeatureUnavailable::class);
        $transport->socketSessionId();
    }
}

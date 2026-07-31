<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutbox;
use App\Infrastructure\Notification\NotificationOutboxProcessor;
use Tests\Integration\IntegrationTestCase;

final class NotificationOutboxProcessorTest extends IntegrationTestCase
{
    public function testSentNotificationIsNotSentAgain(): void
    {
        $id = $this->enqueue();
        $deliveries = 0;
        $processor = new NotificationOutboxProcessor(
            $this->db(),
            static function () use (&$deliveries): void {
                $deliveries++;
            },
        );

        $first = $processor->processAvailableBatch(20);
        $second = $processor->processAvailableBatch(20);

        self::assertSame(1, $first['sent']);
        self::assertSame(0, $second['sent']);
        self::assertSame(1, $deliveries);
        self::assertSame('sent', $this->scalar(
            'SELECT status FROM {{%notification_outbox}} WHERE id = :id',
            [':id' => $id],
        ));
    }

    public function testFailureKeepsExistingRetryAndBackoffBehavior(): void
    {
        $id = $this->enqueue();
        $processor = new NotificationOutboxProcessor(
            $this->db(),
            static fn(): never => throw new \RuntimeException('SMTP unavailable'),
        );

        $result = $processor->processAvailableBatch(20);
        $row = $this->db()->createCommand(
            'SELECT status, attempts, last_error, next_attempt_at '
            . 'FROM {{%notification_outbox}} WHERE id = :id',
            [':id' => $id],
        )->queryOne();

        self::assertSame(1, $result['failed']);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('SMTP unavailable', $row['last_error']);
        self::assertGreaterThan(Clock::now(), $row['next_attempt_at']);

        $this->db()->createCommand()->update(
            '{{%notification_outbox}}',
            ['next_attempt_at' => Clock::now()],
            ['id' => $id],
        )->execute();
        $deliveries = 0;
        $retry = new NotificationOutboxProcessor(
            $this->db(),
            static function () use (&$deliveries): void {
                $deliveries++;
            },
        );
        $retryResult = $retry->processAvailableBatch(20);

        self::assertSame(1, $retryResult['sent']);
        self::assertSame(1, $deliveries);
        self::assertSame(2, (int) $this->scalar(
            'SELECT attempts FROM {{%notification_outbox}} WHERE id = :id',
            [':id' => $id],
        ));
    }

    public function testExpiredSendingLeaseIsRecovered(): void
    {
        $id = $this->enqueue();
        $this->db()->createCommand()->update(
            '{{%notification_outbox}}',
            [
                'status' => 'sending',
                'next_attempt_at' => '2000-01-01 00:00:00.000000',
            ],
            ['id' => $id],
        )->execute();
        $deliveries = 0;
        $processor = new NotificationOutboxProcessor(
            $this->db(),
            static function () use (&$deliveries): void {
                $deliveries++;
            },
        );

        $result = $processor->processAvailableBatch(20);

        self::assertSame(1, $result['sent']);
        self::assertSame(1, $deliveries);
        self::assertSame(1, (int) $this->scalar(
            'SELECT attempts FROM {{%notification_outbox}} WHERE id = :id',
            [':id' => $id],
        ));
    }

    public function testStopsBetweenMessagesWhenShutdownIsRequested(): void
    {
        $firstId = $this->enqueue();
        $secondId = $this->enqueue();
        $deliveries = 0;
        $continue = true;
        $processor = new NotificationOutboxProcessor(
            $this->db(),
            static function () use (&$deliveries, &$continue): void {
                $deliveries++;
                $continue = false;
            },
        );

        $result = $processor->processAvailableBatch(
            20,
            false,
            static function () use (&$continue): bool {
                return $continue;
            },
        );

        self::assertSame(1, $result['sent']);
        self::assertSame(1, $deliveries);
        self::assertSame('sent', $this->scalar(
            'SELECT status FROM {{%notification_outbox}} WHERE id = :id',
            [':id' => $firstId],
        ));
        self::assertSame('pending', $this->scalar(
            'SELECT status FROM {{%notification_outbox}} WHERE id = :id',
            [':id' => $secondId],
        ));
    }

    public function testExpiredLeaseCanCauseAtLeastOnceRedelivery(): void
    {
        $id = $this->enqueue();
        $deliveries = 0;
        $secondProcessor = new NotificationOutboxProcessor(
            $this->db(),
            static function () use (&$deliveries): void {
                $deliveries++;
            },
        );
        $firstProcessor = new NotificationOutboxProcessor(
            $this->db(),
            function () use (&$deliveries, $id, $secondProcessor): void {
                $deliveries++;
                $this->db()->createCommand()->update(
                    '{{%notification_outbox}}',
                    ['next_attempt_at' => '2000-01-01 00:00:00.000000'],
                    ['id' => $id, 'status' => 'sending'],
                )->execute();
                $secondProcessor->processAvailableBatch(20);
            },
        );

        $firstProcessor->processAvailableBatch(20);

        self::assertSame(2, $deliveries);
        self::assertSame('sent', $this->scalar(
            'SELECT status FROM {{%notification_outbox}} WHERE id = :id',
            [':id' => $id],
        ));
    }

    private function enqueue(): int
    {
        $userId = $this->createUser(uniqid('worker.', true), 'Worker Test');
        $now = Clock::now();
        $this->db()->createCommand()->insert('{{%requests}}', [
            'number' => random_int(1_000_000, 9_999_999),
            'initiator_id' => $userId,
            'status' => 'registered',
            'product_name' => 'Тест',
            'manufacturer' => 'Тест',
            'supplier' => 'Тест',
            'sample_quantity' => 1,
            'test_method' => 'Тест',
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        $requestId = (int) $this->db()->getLastInsertID();

        (new NotificationOutbox($this->db()))->enqueue(
            $requestId,
            'test.worker',
            'worker@example.invalid',
            'Worker Test',
            'Тема',
            'Тело',
        );
        return (int) $this->db()->getLastInsertID();
    }
}

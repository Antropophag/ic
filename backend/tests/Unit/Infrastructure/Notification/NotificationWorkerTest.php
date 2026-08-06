<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notification;

use App\Infrastructure\Notification\NotificationWorker;
use PHPUnit\Framework\TestCase;

final class NotificationWorkerTest extends TestCase
{
    public function testContinuesImmediatelyAfterNonEmptyBatch(): void
    {
        $calls = 0;
        $sleeps = [];
        $worker = null;
        $worker = new NotificationWorker(
            static function (callable $shouldContinue) use (&$calls, &$worker): int {
                self::assertTrue($shouldContinue());
                $calls++;
                if ($calls === 2) {
                    $worker->requestShutdown();
                }
                return 1;
            },
            static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
            static function (): void {
            },
            2,
            5,
        );
        $worker->run();

        self::assertSame(2, $calls);
        self::assertSame([], $sleeps);
    }

    public function testSleepsAfterEmptyBatch(): void
    {
        $sleeps = [];
        $worker = null;
        $worker = new NotificationWorker(
            static fn(callable $shouldContinue): int => 0,
            static function (int $seconds) use (&$sleeps, &$worker): void {
                $sleeps[] = $seconds;
                $worker->requestShutdown();
            },
            static function (): void {
            },
            2,
            5,
        );
        $worker->run();

        self::assertSame([2], $sleeps);
    }

    public function testContinuesAfterUnexpectedError(): void
    {
        $calls = 0;
        $errors = [];
        $sleeps = [];
        $worker = null;
        $worker = new NotificationWorker(
            static function (callable $shouldContinue) use (&$calls, &$worker): int {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException('database unavailable');
                }
                $worker->requestShutdown();
                return 0;
            },
            static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
            static function (\Throwable $error) use (&$errors): void {
                $errors[] = $error->getMessage();
            },
            2,
            5,
        );
        $worker->run();

        self::assertSame(2, $calls);
        self::assertSame(['database unavailable'], $errors);
        self::assertSame([5], $sleeps);
    }

    public function testShutdownPreventsTakingAnotherBatch(): void
    {
        $calls = 0;
        $worker = null;
        $worker = new NotificationWorker(
            static function (callable $shouldContinue) use (&$calls, &$worker): int {
                $calls++;
                $worker->requestShutdown();
                self::assertFalse($shouldContinue());
                return 1;
            },
            static function (): void {
            },
            static function (): void {
            },
            2,
            5,
        );
        $worker->run();

        self::assertSame(1, $calls);
    }
}

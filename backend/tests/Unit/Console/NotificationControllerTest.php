<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\NotificationController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\base\Module;
use yii\console\ExitCode;
use yii\console\Request;
use yii\console\Response;

final class NotificationControllerTest extends TestCase
{
    public function testWorkDoesNotAcceptIncludeFailedOption(): void
    {
        $controller = $this->controller();

        self::assertNotContains('includeFailed', $controller->options('work'));
        self::assertContains('includeFailed', $controller->options('send'));
    }

    #[DataProvider('invalidWorkerDelayProvider')]
    public function testWorkRejectsBusyLoopDelay(string $option): void
    {
        $controller = $this->controller();
        $controller->{$option} = 0;

        self::assertSame(ExitCode::USAGE, $controller->actionWork());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidWorkerDelayProvider(): iterable
    {
        yield 'idle sleep' => ['idleSleep'];
        yield 'error sleep' => ['errorSleep'];
    }

    private function controller(): NotificationController
    {
        return new NotificationController('notification', new Module('test'), [
            'request' => new Request(),
            'response' => new Response(),
        ]);
    }
}

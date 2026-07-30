<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use PHPUnit\Framework\TestCase;
use yii\log\Logger;

final class StderrTargetTest extends TestCase
{
    public function testExportsEveryFormattedMessage(): void
    {
        $target = new RecordingStderrTarget();
        $target->prefix = static fn (): string => '';
        $target->messages = [
            ['First failure', Logger::LEVEL_ERROR, 'application', 0.0],
            [['reason' => 'Second failure'], Logger::LEVEL_WARNING, 'worker', 1.0],
        ];

        $target->export();

        self::assertCount(2, $target->written);
        self::assertStringContainsString('[error][application] First failure', $target->written[0]);
        self::assertStringContainsString("[warning][worker] [\n    'reason' => 'Second failure',\n]", $target->written[1]);
    }

    public function testContinuesWhenStderrRejectsMessage(): void
    {
        $target = new RecordingStderrTarget(false);
        $target->messages = [
            ['First failure', Logger::LEVEL_ERROR, 'application', 0.0],
            ['Second failure', Logger::LEVEL_ERROR, 'application', 1.0],
        ];

        $target->export();

        self::assertCount(2, $target->written);
    }

    public function testContinuesWhenStderrOnlyWritesPartOfMessage(): void
    {
        $target = new RecordingStderrTarget(1);
        $target->messages = [
            ['First failure', Logger::LEVEL_ERROR, 'application', 0.0],
            ['Second failure', Logger::LEVEL_ERROR, 'application', 1.0],
        ];

        $target->export();

        self::assertCount(2, $target->written);
    }
}

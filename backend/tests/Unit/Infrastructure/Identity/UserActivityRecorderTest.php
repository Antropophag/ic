<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Identity;

use App\Infrastructure\Identity\UserActivityRecorder;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\db\Connection;

final class UserActivityRecorderTest extends TestCase
{
    public function testLoginTimestampFailureIsBestEffort(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('createCommand')
            ->willThrowException(new RuntimeException('simulated database failure'));

        (new UserActivityRecorder($db))->recordLogin(42);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ReasonedLockVersionInput;
use PHPUnit\Framework\TestCase;

final class ReasonedLockVersionInputTest extends TestCase
{
    public function testAcceptsAndTrimsReasonWithLockVersion(): void
    {
        $input = new ReasonedLockVersionInput();
        $input->reason = '  Требуется уточнение  ';
        $input->lockVersion = 2;

        self::assertTrue($input->validate());
        self::assertSame('Требуется уточнение', $input->reason);
    }

    public function testRejectsBlankReason(): void
    {
        $input = new ReasonedLockVersionInput();
        $input->reason = '   ';
        $input->lockVersion = 2;

        self::assertFalse($input->validate());
        self::assertArrayHasKey('reason', $input->errors);
    }
}

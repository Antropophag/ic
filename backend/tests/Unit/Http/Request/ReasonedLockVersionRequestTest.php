<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Request;

use App\Http\Request\ReasonedLockVersionRequest;
use PHPUnit\Framework\TestCase;

final class ReasonedLockVersionRequestTest extends TestCase
{
    public function testAcceptsAndTrimsReasonWithLockVersion(): void
    {
        $input = new ReasonedLockVersionRequest();
        $input->reason = '  Требуется уточнение  ';
        $input->lockVersion = 2;

        self::assertTrue($input->validate());
        self::assertSame('Требуется уточнение', $input->reason);
    }

    public function testRejectsBlankReason(): void
    {
        $input = new ReasonedLockVersionRequest();
        $input->reason = '   ';
        $input->lockVersion = 2;

        self::assertFalse($input->validate());
        self::assertArrayHasKey('reason', $input->errors);
    }
}

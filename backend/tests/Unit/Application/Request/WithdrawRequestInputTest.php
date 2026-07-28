<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\WithdrawRequestInput;
use PHPUnit\Framework\TestCase;

final class WithdrawRequestInputTest extends TestCase
{
    public function testPositiveLockVersionIsValid(): void
    {
        $input = new WithdrawRequestInput();
        $input->lockVersion = 1;

        self::assertTrue($input->validate());
    }

    public function testMissingOrInvalidLockVersionIsRejected(): void
    {
        $input = new WithdrawRequestInput();
        self::assertFalse($input->validate());

        $input->lockVersion = 0;
        self::assertFalse($input->validate());

        $input->lockVersion = 'not-a-version';
        self::assertFalse($input->validate());
    }
}

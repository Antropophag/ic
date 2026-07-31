<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ResumeRequestInput;
use PHPUnit\Framework\TestCase;

final class ResumeRequestInputTest extends TestCase
{
    public function testPositiveLockVersionIsValid(): void
    {
        $input = new ResumeRequestInput();
        $input->lockVersion = 1;

        self::assertTrue($input->validate());
    }

    public function testMissingOrInvalidLockVersionIsRejected(): void
    {
        $input = new ResumeRequestInput();
        self::assertFalse($input->validate());

        $input->lockVersion = 0;
        self::assertFalse($input->validate());

        $input->lockVersion = 'not-a-version';
        self::assertFalse($input->validate());
    }
}

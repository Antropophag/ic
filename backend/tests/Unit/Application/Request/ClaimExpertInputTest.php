<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ClaimExpertInput;
use PHPUnit\Framework\TestCase;

final class ClaimExpertInputTest extends TestCase
{
    public function testPositiveLockVersionIsValid(): void
    {
        $input = new ClaimExpertInput();
        $input->lockVersion = 1;

        self::assertTrue($input->validate());
    }

    public function testMissingOrInvalidLockVersionIsRejected(): void
    {
        $input = new ClaimExpertInput();
        self::assertFalse($input->validate());

        $input->lockVersion = 0;
        self::assertFalse($input->validate());

        $input->lockVersion = 'not-a-version';
        self::assertFalse($input->validate());
    }
}

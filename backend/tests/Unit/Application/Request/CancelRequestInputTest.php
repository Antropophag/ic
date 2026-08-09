<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\CancelRequestInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CancelRequestInputTest extends TestCase
{
    public function testRejectsMissingReason(): void
    {
        $input = new CancelRequestInput();
        $input->lockVersion = 1;

        self::assertFalse($input->validate());
        self::assertArrayHasKey('reason', $input->errors);
    }

    public function testTrimsRequiredReason(): void
    {
        $input = new CancelRequestInput();
        $input->reason = '  reason  ';
        $input->lockVersion = 1;

        self::assertTrue($input->validate());
        self::assertSame('reason', $input->reason);
    }

    public function testRejectsBlankReason(): void
    {
        $input = new CancelRequestInput();
        $input->reason = '   ';
        $input->lockVersion = 1;

        self::assertFalse($input->validate());
        self::assertArrayHasKey('reason', $input->errors);
    }

    public function testRejectsReasonLongerThanLimit(): void
    {
        $input = new CancelRequestInput();
        $input->reason = str_repeat('a', 5001);
        $input->lockVersion = 1;

        self::assertFalse($input->validate());
        self::assertArrayHasKey('reason', $input->errors);
    }

    #[DataProvider('invalidLockVersionProvider')]
    public function testRejectsInvalidLockVersion(mixed $lockVersion): void
    {
        $input = new CancelRequestInput();
        $input->lockVersion = $lockVersion;

        self::assertFalse($input->validate());
        self::assertArrayHasKey('lockVersion', $input->errors);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidLockVersionProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'zero' => [0];
        yield 'non-numeric string' => ['not-a-version'];
    }
}

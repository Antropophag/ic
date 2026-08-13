<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Request;

use App\Http\Request\LockVersionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LockVersionRequestTest extends TestCase
{
    public function testAcceptsPositiveIntegerLockVersion(): void
    {
        $input = new LockVersionRequest();
        $input->lockVersion = 1;

        self::assertTrue($input->validate());
    }

    #[DataProvider('invalidLockVersionProvider')]
    public function testRejectsInvalidLockVersion(mixed $lockVersion): void
    {
        $input = new LockVersionRequest();
        $input->lockVersion = $lockVersion;

        self::assertFalse($input->validate());
        self::assertArrayHasKey('lockVersion', $input->errors);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidLockVersionProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'zero' => [0];
        yield 'non-numeric string' => ['not-a-version'];
    }
}

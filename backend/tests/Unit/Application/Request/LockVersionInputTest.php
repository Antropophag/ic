<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\LockVersionInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LockVersionInputTest extends TestCase
{
    public function testAcceptsPositiveIntegerLockVersion(): void
    {
        $input = new LockVersionInput();
        $input->lockVersion = 1;

        self::assertTrue($input->validate());
    }

    #[DataProvider('invalidLockVersionProvider')]
    public function testRejectsInvalidLockVersion(mixed $lockVersion): void
    {
        $input = new LockVersionInput();
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

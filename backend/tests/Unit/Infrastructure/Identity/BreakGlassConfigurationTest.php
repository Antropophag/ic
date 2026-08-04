<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Identity;

use App\Infrastructure\Identity\BreakGlassConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BreakGlassConfigurationTest extends TestCase
{
    public function testAbsentConfigurationDisablesMechanism(): void
    {
        $configuration = new BreakGlassConfiguration(null, null);

        self::assertTrue($configuration->isDisabled());
        self::assertFalse($configuration->isValid());
        self::assertFalse($configuration->matches('emergency.admin'));
        self::assertNull($configuration->errorCode());
    }

    public function testValidConfigurationMatchesLoginExactlyAndVerifiesPassword(): void
    {
        $configuration = new BreakGlassConfiguration(
            'Emergency.Admin',
            password_hash('correct horse battery staple', PASSWORD_DEFAULT),
        );

        self::assertTrue($configuration->isValid());
        self::assertTrue($configuration->matches('Emergency.Admin'));
        self::assertFalse($configuration->matches('emergency.admin'));
        self::assertTrue($configuration->verify('correct horse battery staple'));
        self::assertFalse($configuration->verify('wrong password'));
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testIncompleteOrMalformedConfigurationIsInvalid(
        ?string $login,
        ?string $passwordHash,
        string $errorCode,
    ): void {
        $configuration = new BreakGlassConfiguration($login, $passwordHash);

        self::assertFalse($configuration->isDisabled());
        self::assertFalse($configuration->isValid());
        self::assertSame($errorCode, $configuration->errorCode());
        self::assertFalse($configuration->verify('any password'));
    }

    /** @return iterable<string, array{string|null, string|null, string}> */
    public static function invalidConfigurationProvider(): iterable
    {
        $validHash = password_hash('test password', PASSWORD_DEFAULT);
        yield 'missing login' => [null, $validHash, 'missing_login'];
        yield 'missing hash' => ['emergency.admin', null, 'missing_password_hash'];
        yield 'invalid login' => ['emergency admin', $validHash, 'invalid_login'];
        yield 'reserved technical login' => ['__break_glass__', $validHash, 'invalid_login'];
        yield 'invalid hash' => ['emergency.admin', 'not-a-password-hash', 'invalid_password_hash'];
    }
}

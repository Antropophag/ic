<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Deployment;

use App\Infrastructure\Deployment\DatabasePurpose;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabasePurposeTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function developmentNames(): iterable
    {
        yield 'explicit dev suffix' => ['ic_dev', true];
        yield 'custom dev database' => ['team_portal_dev', true];
        yield 'production database' => ['ic', false];
        yield 'test database' => ['ic_test', false];
        yield 'embedded marker is insufficient' => ['ic_dev_backup', false];
    }

    #[DataProvider('developmentNames')]
    public function testDevelopmentDatabaseMarker(string $database, bool $expected): void
    {
        self::assertSame($expected, DatabasePurpose::isDevelopment($database));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function databaseNamesForTestPurpose(): iterable
    {
        yield 'explicit test suffix' => ['ic_test', true];
        yield 'custom test database' => ['team_portal_test', true];
        yield 'production database' => ['ic', false];
        yield 'development database' => ['ic_dev', false];
        yield 'embedded marker is insufficient' => ['ic_test_backup', false];
    }

    #[DataProvider('databaseNamesForTestPurpose')]
    public function testTestDatabaseMarker(string $database, bool $expected): void
    {
        self::assertSame($expected, DatabasePurpose::isTest($database));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function identityHeaders(): iterable
    {
        yield 'development header on development database' => [
            'ic_dev',
            DatabasePurpose::DEVELOPMENT_IDENTITY_HEADER,
            true,
        ];
        yield 'development header on production database' => [
            'ic',
            DatabasePurpose::DEVELOPMENT_IDENTITY_HEADER,
            false,
        ];
        yield 'development header on test database' => [
            'ic_test',
            DatabasePurpose::DEVELOPMENT_IDENTITY_HEADER,
            false,
        ];
        yield 'test header on test database' => [
            'ic_test',
            DatabasePurpose::TEST_IDENTITY_HEADER,
            true,
        ];
        yield 'test header on production database' => [
            'ic',
            DatabasePurpose::TEST_IDENTITY_HEADER,
            false,
        ];
        yield 'test header on development database' => [
            'ic_dev',
            DatabasePurpose::TEST_IDENTITY_HEADER,
            false,
        ];
        yield 'arbitrary header is never trusted' => ['ic_dev', 'X-User-ID', false];
    }

    #[DataProvider('identityHeaders')]
    public function testIdentityHeaderMustMatchDatabasePurpose(
        string $database,
        string $header,
        bool $expected,
    ): void {
        self::assertSame($expected, DatabasePurpose::allowsIdentityHeader($database, $header));
    }
}

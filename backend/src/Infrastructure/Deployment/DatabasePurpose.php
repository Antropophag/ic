<?php

declare(strict_types=1);

namespace App\Infrastructure\Deployment;

final class DatabasePurpose
{
    public const DEVELOPMENT_IDENTITY_HEADER = 'X-Dev-User-ID';
    public const TEST_IDENTITY_HEADER = 'X-Test-User-ID';

    public static function isDevelopment(string $database): bool
    {
        return str_ends_with($database, '_dev');
    }

    public static function isTest(string $database): bool
    {
        return str_ends_with($database, '_test');
    }

    public static function allowsIdentityHeader(string $database, string $header): bool
    {
        return match ($header) {
            self::DEVELOPMENT_IDENTITY_HEADER => self::isDevelopment($database),
            self::TEST_IDENTITY_HEADER => self::isTest($database),
            default => false,
        };
    }
}

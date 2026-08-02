<?php

declare(strict_types=1);

namespace App\Infrastructure\Deployment;

final class DatabasePurpose
{
    public static function isDevelopment(string $database): bool
    {
        return str_ends_with($database, '_dev');
    }

    public static function isTest(string $database): bool
    {
        return str_ends_with($database, '_test');
    }
}

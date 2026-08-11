<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Admin;

use App\Infrastructure\Admin\SystemOverviewQuery;
use PHPUnit\Framework\TestCase;

final class SystemOverviewQueryTest extends TestCase
{
    public function testFailuresAreIndependentAndSecretsStayOutOfResponse(): void
    {
        $details = [
            'database' => ['База данных' => 'ic'],
            'ldap' => ['Сервер' => 'ldap.example.test:389'],
            'smtp' => ['Сервер' => 'smtp.example.test:587'],
            'storage' => ['Путь' => '/app/storage/documents'],
        ];
        $result = (new SystemOverviewQuery(
            ['name' => 'ИЦ', 'version' => '1', 'commitSha' => null, 'builtAt' => null],
            $details,
            [
                'database' => static fn (): array => ['Сервер' => 'MariaDB 11.4'],
                'smtp' => static fn (): never => throw new \RuntimeException('password=secret'),
                'storage' => static fn (): array => [],
                'ldap' => static fn (): array => [],
            ],
        ))->read();
        self::assertSame('operational', $result['services']['database']['status']);
        self::assertSame('error', $result['services']['smtp']['status']);
        self::assertSame('operational', $result['services']['storage']['status']);
        self::assertSame('operational', $result['services']['ldap']['status']);
        self::assertSame('MariaDB 11.4', $result['services']['database']['details']['Сервер']);
        self::assertSame($details['ldap'], $result['services']['ldap']['details']);
        self::assertSame('Соединение установлено', $result['services']['ldap']['message']);
        self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
    }
}

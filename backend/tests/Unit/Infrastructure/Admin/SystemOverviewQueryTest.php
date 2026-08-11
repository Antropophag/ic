<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Admin;

use App\Infrastructure\Admin\SystemOverviewQuery;
use PHPUnit\Framework\TestCase;

final class SystemOverviewQueryTest extends TestCase
{
    public function testFailuresAreIndependentAndSecretsStayOutOfResponse(): void
    {
        $result = (new SystemOverviewQuery(['name' => 'ИЦ','version' => '1','commitSha' => null,'builtAt' => null], ['database' => static function (): void {
        },'smtp' => static function (): void {
            throw new \RuntimeException('password=secret');
        },'storage' => static function (): void {
        }]))->read();
        self::assertSame('operational', $result['services']['database']['status']);
        self::assertSame('error', $result['services']['smtp']['status']);
        self::assertSame('operational', $result['services']['storage']['status']);
        self::assertSame('unavailable', $result['services']['ldap']['status']);
        self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
    }
}

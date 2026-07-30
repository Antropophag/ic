<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notification;

use App\Infrastructure\Notification\RequestUrl;
use PHPUnit\Framework\TestCase;

final class RequestUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_PUBLIC_URL');
    }

    public function testBuildsRequestDeepLink(): void
    {
        putenv('APP_PUBLIC_URL=https://portal.test/base/');
        self::assertSame('https://portal.test/base/?request=123', RequestUrl::build(123));
    }

    public function testRequiresPublicUrl(): void
    {
        putenv('APP_PUBLIC_URL');
        $this->expectException(\RuntimeException::class);
        RequestUrl::build(1);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notification;

use App\Infrastructure\Notification\RequestUrl;
use PHPUnit\Framework\TestCase;

final class RequestUrlTest extends TestCase
{
    private string|false $original;

    protected function setUp(): void
    {
        $this->original = getenv('APP_PUBLIC_URL');
    }

    protected function tearDown(): void
    {
        $this->original === false
            ? putenv('APP_PUBLIC_URL')
            : putenv('APP_PUBLIC_URL=' . $this->original);
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

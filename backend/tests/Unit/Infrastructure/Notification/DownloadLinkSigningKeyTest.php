<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notification;

use App\Infrastructure\Notification\DownloadLinkSigningKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DownloadLinkSigningKeyTest extends TestCase
{
    private string|false $original;

    protected function setUp(): void
    {
        $this->original = getenv('DOWNLOAD_LINK_SIGNING_KEY');
    }

    protected function tearDown(): void
    {
        $this->original === false
            ? putenv('DOWNLOAD_LINK_SIGNING_KEY')
            : putenv('DOWNLOAD_LINK_SIGNING_KEY=' . $this->original);
    }

    public function testAcceptsConfiguredNonPlaceholderKey(): void
    {
        putenv('DOWNLOAD_LINK_SIGNING_KEY=test-only-non-placeholder-signing-key');

        self::assertSame('test-only-non-placeholder-signing-key', DownloadLinkSigningKey::get());
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsUnsafeDeploymentValue(?string $value): void
    {
        $value === null
            ? putenv('DOWNLOAD_LINK_SIGNING_KEY')
            : putenv('DOWNLOAD_LINK_SIGNING_KEY=' . $value);

        $this->expectException(\RuntimeException::class);
        DownloadLinkSigningKey::get();
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidKeys(): iterable
    {
        yield 'missing' => [null];
        yield 'short' => ['short'];
        yield 'production placeholder' => ['replace-with-a-different-at-least-32-random-characters'];
        yield 'development placeholder' => ['replace-with-a-different-local-random-value'];
    }
}

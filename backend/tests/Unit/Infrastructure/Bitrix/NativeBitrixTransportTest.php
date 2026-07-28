<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bitrix;

use App\Infrastructure\Bitrix\NativeBitrixTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativeBitrixTransportTest extends TestCase
{
    public function testRejectsMutatingMethodBeforeNetworkAccess(): void
    {
        $calls = 0;
        $transport = new NativeBitrixTransport(
            'https://example.invalid/rest/token',
            fetch: static function (string $_url, mixed $_context) use (&$calls): string {
                ++$calls;
                return '{}';
            },
            retryDelayMilliseconds: 0,
        );

        try {
            $transport->call('lists.element.add');
            self::fail('Mutating Bitrix24 method must be rejected.');
        } catch (RuntimeException) {
            self::assertSame(0, $calls);
        }
    }

    public function testRetriesMalformedResponse(): void
    {
        $responses = ['not-json', '{"result":{"NAME":{}}}'];
        $transport = new NativeBitrixTransport(
            'https://example.invalid/rest/token',
            fetch: static function (string $_url, mixed $_context) use (&$responses): string {
                return (string) array_shift($responses);
            },
            retryDelayMilliseconds: 0,
        );

        self::assertSame(['NAME' => []], $transport->call('lists.field.get')['result']);
        self::assertCount(0, $responses);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bitrix;

use App\Infrastructure\Bitrix\BitrixListClient;
use App\Infrastructure\Bitrix\BitrixTransport;
use PHPUnit\Framework\TestCase;

final class BitrixListClientTest extends TestCase
{
    public function testReadsEveryPageWithoutMutatingMethods(): void
    {
        $transport = new class () implements BitrixTransport {
            /** @var list<array{string, array<string, mixed>}> */
            public array $calls = [];

            public function call(string $method, array $parameters = []): array
            {
                $this->calls[] = [$method, $parameters];
                return ($parameters['start'] ?? 0) === 0
                    ? ['result' => [['ID' => '1']], 'next' => 50]
                    : ['result' => [['ID' => '2']]];
            }
        };

        $items = iterator_to_array((new BitrixListClient($transport, 'lists', 114, 0))->elements());

        self::assertSame(['1', '2'], array_column($items, 'ID'));
        self::assertSame(['lists.element.get', 'lists.element.get'], array_column($transport->calls, 0));
        self::assertSame(['ID' => 'asc'], $transport->calls[0][1]['ELEMENT_ORDER']);
        self::assertSame(50, $transport->calls[1][1]['start']);
    }

    public function testHonorsPageLimit(): void
    {
        $transport = new class () implements BitrixTransport {
            public function call(string $method, array $parameters = []): array
            {
                return ['result' => [['ID' => '1']], 'next' => 50];
            }
        };

        self::assertCount(1, iterator_to_array(
            (new BitrixListClient($transport, 'lists', 114, 0))->elements(1),
        ));
    }

    public function testRejectsMalformedCursor(): void
    {
        $transport = new class () implements BitrixTransport {
            public function call(string $method, array $parameters = []): array
            {
                return ['result' => [], 'next' => 'not-a-number'];
            }
        };

        $this->expectException(\RuntimeException::class);
        iterator_to_array((new BitrixListClient($transport, 'lists', 114, 0))->elements());
    }

    public function testRejectsNonAdvancingCursor(): void
    {
        $transport = new class () implements BitrixTransport {
            public function call(string $method, array $parameters = []): array
            {
                return ['result' => [], 'next' => $parameters['start'] ?? 0];
            }
        };

        $this->expectException(\RuntimeException::class);
        iterator_to_array((new BitrixListClient($transport, 'lists', 114, 0))->elements());
    }

    public function testRejectsNegativePageLimitBeforeCallingBitrix(): void
    {
        $transport = new class () implements BitrixTransport {
            public function call(string $method, array $parameters = []): array
            {
                \PHPUnit\Framework\Assert::fail('Transport must not be called for an invalid page limit.');
            }
        };

        $this->expectException(\RuntimeException::class);
        iterator_to_array((new BitrixListClient($transport, 'lists', 114, 0))->elements(-1));
    }
}

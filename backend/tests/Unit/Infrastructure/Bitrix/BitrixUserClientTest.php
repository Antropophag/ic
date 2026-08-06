<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bitrix;

use App\Infrastructure\Bitrix\BitrixTransport;
use App\Infrastructure\Bitrix\BitrixUserClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BitrixUserClientTest extends TestCase
{
    public function testLoadsEachUniqueUserWithIdFilter(): void
    {
        $transport = new class () implements BitrixTransport {
            /** @var list<array{string, array<string, mixed>}> */
            public array $calls = [];

            public function call(string $method, array $parameters = []): array
            {
                $this->calls[] = [$method, $parameters];
                $id = (string) $parameters['FILTER']['ID'];
                return ['result' => [['ID' => $id]]];
            }
        };

        $users = (new BitrixUserClient($transport, 0))->usersById(['7', '8', '7']);

        self::assertSame([7, 8], array_keys($users));
        self::assertSame([
            ['user.get', ['FILTER' => ['ID' => '7']]],
            ['user.get', ['FILTER' => ['ID' => '8']]],
        ], $transport->calls);
    }

    public function testRejectsMissingOrAmbiguousUser(): void
    {
        $transport = new class () implements BitrixTransport {
            public function call(string $method, array $parameters = []): array
            {
                return ['result' => []];
            }
        };

        $this->expectException(RuntimeException::class);
        (new BitrixUserClient($transport, 0))->usersById(['7']);
    }
}

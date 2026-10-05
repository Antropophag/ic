<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ChooseRouteInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChooseRouteInputTest extends TestCase
{
    public function testAcceptsOnlyNamedRoutes(): void
    {
        foreach (['act', 'protocol'] as $route) {
            $input = new ChooseRouteInput();
            $input->load(['route' => $route, 'lockVersion' => 1], '');
            self::assertTrue($input->validate());
        }
    }

    #[DataProvider('invalidRoutes')]
    public function testRejectsInvalidRouteTypes(mixed $route): void
    {
        $input = new ChooseRouteInput();
        $input->load(['route' => $route, 'lockVersion' => 1], '');
        self::assertFalse($input->validate());
        self::assertArrayHasKey('route', $input->errors);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidRoutes(): iterable
    {
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'number' => [1];
        yield 'array' => [['act']];
        yield 'object' => [(object) ['route' => 'act']];
        yield 'empty' => [''];
        yield 'missing' => [null];
        yield 'unknown' => ['other'];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ChooseRouteInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChooseRouteInputTest extends TestCase
{
    public function testSecurityReviewChoiceRequiresJsonBoolean(): void
    {
        foreach ([true, false] as $required) {
            $input = new ChooseRouteInput();
            $input->load(['route' => 'protocol', 'lockVersion' => 1, 'securityReviewRequired' => $required], '');
            self::assertTrue($input->validate());
            self::assertSame($required, $input->securityReviewRequired);
        }
        foreach ([null, 0, 1, '', 'false', 'true', [], new \stdClass()] as $invalid) {
            $input = new ChooseRouteInput();
            $input->load(['route' => 'protocol', 'lockVersion' => 1, 'securityReviewRequired' => $invalid], '');
            self::assertFalse($input->validate());
            self::assertArrayHasKey('securityReviewRequired', $input->errors);
        }
    }

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

<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ListRequestsInput;
use PHPUnit\Framework\TestCase;

final class ListRequestsInputTest extends TestCase
{
    public function testAcceptsMultipleDirectionsAndNormalizesDuplicates(): void
    {
        $input = new ListRequestsInput(['colors' => 'blue, orange,blue']);
        self::assertTrue($input->validate());
        self::assertSame(['blue', 'orange'], $input->colorValues());
        self::assertSame([], (new ListRequestsInput())->colorValues());
    }

    public function testRejectsUnknownEmptyAndNonScalarDirections(): void
    {
        foreach (['yellow', 'blue,,red', 'blue,', ['blue'], [], null, 'blue) OR 1=1'] as $colors) {
            $input = new ListRequestsInput(['colors' => $colors]);
            self::assertFalse($input->validate());
            self::assertArrayHasKey('colors', $input->errors);
        }
    }

    public function testAcceptsKnownAttentionQueue(): void
    {
        $input = new ListRequestsInput(['attention' => 'assign_executor']);

        self::assertTrue($input->validate());
    }

    public function testRejectsUnknownAttentionQueue(): void
    {
        $input = new ListRequestsInput(['attention' => 'someone_else']);

        self::assertFalse($input->validate());
        self::assertArrayHasKey('attention', $input->errors);
    }
}

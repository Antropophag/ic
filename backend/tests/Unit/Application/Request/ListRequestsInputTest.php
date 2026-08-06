<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\ListRequestsInput;
use PHPUnit\Framework\TestCase;

final class ListRequestsInputTest extends TestCase
{
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

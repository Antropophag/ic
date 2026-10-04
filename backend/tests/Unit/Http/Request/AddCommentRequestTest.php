<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Request;

use App\Http\Request\AddCommentRequest;
use PHPUnit\Framework\TestCase;

final class AddCommentRequestTest extends TestCase
{
    public function testTrimsAndAcceptsComment(): void
    {
        $input = new AddCommentRequest(['body' => '  Комментарий  ']);
        self::assertTrue($input->validate());
        self::assertSame('Комментарий', $input->body);
    }

    public function testRejectsWhitespaceAndOversizedComment(): void
    {
        $empty = new AddCommentRequest(['body' => '   ']);
        $long = new AddCommentRequest(['body' => str_repeat('я', 10001)]);
        self::assertFalse($empty->validate());
        self::assertFalse($long->validate());
    }
}

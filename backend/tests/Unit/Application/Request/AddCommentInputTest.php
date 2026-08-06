<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\AddCommentInput;
use PHPUnit\Framework\TestCase;

final class AddCommentInputTest extends TestCase
{
    public function testTrimsAndAcceptsComment(): void
    {
        $input = new AddCommentInput(['body' => '  Комментарий  ']);
        self::assertTrue($input->validate());
        self::assertSame('Комментарий', $input->body);
    }

    public function testRejectsWhitespaceAndOversizedComment(): void
    {
        $empty = new AddCommentInput(['body' => '   ']);
        $long = new AddCommentInput(['body' => str_repeat('я', 10001)]);
        self::assertFalse($empty->validate());
        self::assertFalse($long->validate());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Document;

use App\Application\Document\TestActInput;
use PHPUnit\Framework\TestCase;

final class TestActInputTest extends TestCase
{
    public function testRejectsNonStringFieldsWithoutTypeError(): void
    {
        $input = new TestActInput();
        $input->setAttributes([
            'actNumber' => ['42'],
            'actDate' => ['11.08.2026'],
            'basis' => ['Заявка № 42'],
            'result' => ['Результат'],
        ]);

        self::assertFalse($input->validate());
        self::assertSame(
            ['actNumber', 'actDate', 'basis', 'result'],
            array_keys($input->getErrors()),
        );
    }
}

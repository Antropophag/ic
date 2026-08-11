<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Document;

use App\Application\Document\TestActInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TestActInputTest extends TestCase
{
    public function testAcceptsValidValuesAndTrimsWhitespace(): void
    {
        $input = $this->validInput();
        $input->actNumber = ' 42-А ';
        $input->basis = ' Заявка № 42 ';
        $input->result = ' Испытания пройдены. ';

        self::assertTrue($input->validate());
        self::assertSame('42-А', $input->actNumber);
        self::assertSame('Заявка № 42', $input->basis);
        self::assertSame('Испытания пройдены.', $input->result);
    }

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

    #[DataProvider('invalidValueProvider')]
    public function testRejectsInvalidValue(string $attribute, mixed $value): void
    {
        $input = $this->validInput();
        $input->{$attribute} = $value;

        self::assertFalse($input->validate());
        self::assertArrayHasKey($attribute, $input->getErrors());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidValueProvider(): iterable
    {
        yield 'act number exceeds maximum length' => ['actNumber', str_repeat('А', 101)];
        yield 'basis exceeds maximum length' => ['basis', str_repeat('Б', 1001)];
        yield 'result exceeds maximum length' => ['result', str_repeat('Р', 20001)];
        yield 'date uses ISO format' => ['actDate', '2026-08-11'];
        yield 'required value contains whitespace only' => ['result', " \t\n "];
    }

    private function validInput(): TestActInput
    {
        $input = new TestActInput();
        $input->setAttributes([
            'actNumber' => '42',
            'actDate' => '11.08.2026',
            'basis' => 'Заявка № 42',
            'result' => 'Испытания пройдены.',
        ]);

        return $input;
    }
}

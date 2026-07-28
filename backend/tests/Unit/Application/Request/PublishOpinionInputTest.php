<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\PublishOpinionInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishOpinionInputTest extends TestCase
{
    public function testAcceptsValidOpinion(): void
    {
        $input = new PublishOpinionInput();
        $input->load(['body' => 'Образец соответствует требованиям.', 'lockVersion' => 5], '');

        self::assertTrue($input->validate());
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidData')]
    public function testRejectsInvalidInput(array $data): void
    {
        $input = new PublishOpinionInput();
        $input->load($data, '');

        self::assertFalse($input->validate());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidData(): iterable
    {
        yield 'empty body' => [['body' => '', 'lockVersion' => 5]];
        yield 'short body' => [['body' => 'Коротко', 'lockVersion' => 5]];
        yield 'whitespace around short body' => [['body' => '         Нет         ', 'lockVersion' => 5]];
        yield 'missing lock' => [['body' => 'Достаточно длинное заключение']];
        yield 'invalid lock' => [['body' => 'Достаточно длинное заключение', 'lockVersion' => 0]];
    }
}

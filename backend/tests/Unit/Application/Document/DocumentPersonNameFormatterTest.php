<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Document;

use App\Application\Document\DocumentPersonNameFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentPersonNameFormatterTest extends TestCase
{
    #[DataProvider('names')]
    public function testFormatsDisplayNameForDocumentSignature(string $source, string $expected): void
    {
        self::assertSame($expected, (new DocumentPersonNameFormatter())->abbreviated($source));
    }

    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'full name' => ['Иванов Иван Иванович', 'Иванов И.И.'];
        yield 'without patronymic' => ['Иванов Иван', 'Иванов И.'];
        yield 'extra spaces' => ['  Иванов   Иван   Иванович  ', 'Иванов И.И.'];
        yield 'surname only' => ['Иванов', 'Иванов'];
        yield 'empty' => ['', ''];
    }
}

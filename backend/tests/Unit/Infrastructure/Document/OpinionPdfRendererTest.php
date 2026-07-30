<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Document;

use App\Infrastructure\Document\OpinionPdfRenderer;
use PHPUnit\Framework\TestCase;

final class OpinionPdfRendererTest extends TestCase
{
    /** @return array{number: int, productName: string, manufacturer: string, supplier: string, expertName: string, expertPosition: string, body: string, date: string} */
    private function documentData(): array
    {
        return [
            'number' => 42, 'productName' => '<b>Лифт</b>', 'manufacturer' => 'Завод',
            'supplier' => 'Поставщик', 'expertName' => 'Анна Смирнова',
            'expertPosition' => 'Эксперт', 'body' => '<script>alert(1)</script> Безопасное заключение',
            'date' => '28.07.2026',
        ];
    }

    public function testRendersStyledHtmlWithoutInterpretingUserMarkup(): void
    {
        $html = (new OpinionPdfRenderer())->renderHtml($this->documentData());

        self::assertStringContainsString('class="accent-rail"', $html);
        self::assertStringContainsString('class="document"', $html);
        self::assertStringContainsString('class="facts"', $html);
        self::assertStringContainsString('class="opinion"', $html);
        self::assertStringContainsString('class="signoff"', $html);
        self::assertStringContainsString('background:#253d98', $html);
        self::assertStringContainsString('Заявка № 42', $html);
        self::assertStringContainsString('&lt;b&gt;Лифт&lt;/b&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testRendersPdf(): void
    {
        $result = (new OpinionPdfRenderer())->render($this->documentData());

        self::assertStringStartsWith('%PDF-', $result);
        self::assertGreaterThan(1000, strlen($result));
    }
}

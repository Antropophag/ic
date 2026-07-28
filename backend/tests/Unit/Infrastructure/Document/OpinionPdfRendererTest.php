<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Document;

use App\Infrastructure\Document\OpinionPdfRenderer;
use PHPUnit\Framework\TestCase;

final class OpinionPdfRendererTest extends TestCase
{
    public function testRendersPdfWithoutInterpretingUserHtml(): void
    {
        $result = (new OpinionPdfRenderer())->render([
            'number' => 42, 'productName' => '<b>Лифт</b>', 'manufacturer' => 'Завод',
            'supplier' => 'Поставщик', 'expertName' => 'Анна Смирнова',
            'expertPosition' => 'Эксперт', 'body' => '<script>alert(1)</script> Безопасное заключение',
            'date' => '28.07.2026',
        ]);
        self::assertStringStartsWith('%PDF-', $result);
        self::assertGreaterThan(1000, strlen($result));
    }
}

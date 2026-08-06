<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notification;

use App\Infrastructure\Notification\EmailTemplate;
use PHPUnit\Framework\TestCase;

final class EmailTemplateTest extends TestCase
{
    public function testRendersOutlookCompatibleTemplateAndEscapesContent(): void
    {
        $html = EmailTemplate::render('Заявка <важная>', "Первая строка\nВторая строка", 'https://portal.test/?request=42&from=mail');

        self::assertStringContainsString('<table role="presentation"', $html);
        self::assertStringContainsString('<v:roundrect', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<link', $html);
        self::assertStringContainsString('bgcolor="#253d98"', $html);
        self::assertStringContainsString('Уведомление портала', $html);
        self::assertStringContainsString('Это автоматическое уведомление. Отвечать на него не нужно.', $html);
        self::assertStringContainsString('Испытательный центр', $html);
        self::assertStringContainsString('Заявка &lt;важная&gt;', $html);
        self::assertMatchesRegularExpression('~Первая строка<br>\s*Вторая строка~u', $html);
        self::assertStringContainsString('https://portal.test/?request=42&amp;from=mail', $html);
        self::assertStringNotContainsString('Если кнопка не открывается', $html);
    }

    public function testMakesExistingLinksClickableWithoutAllowingBodyMarkup(): void
    {
        $html = EmailTemplate::render('Тема', '<script>alert(1)</script> https://files.test/a?x=1&y=2', 'https://portal.test/?request=1');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('href="https://files.test/a?x=1&amp;y=2"', $html);
    }

    public function testUsesReadableLabelsForDocumentDownloadLinks(): void
    {
        $html = EmailTemplate::render(
            'Документы готовы',
            "Ссылка на заключение: https://files.test/very-long-token/download\n"
                . 'Ссылка на отчёт: https://files.test/another-very-long-token/download',
            'https://portal.test/?request=1',
        );

        self::assertStringContainsString('>Скачать заключение</a>', $html);
        self::assertStringContainsString('>Скачать отчёт</a>', $html);
        self::assertStringNotContainsString('Ссылка на заключение:', $html);
        self::assertStringNotContainsString('Ссылка на отчёт:', $html);
    }
}

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
        self::assertStringContainsString('Испытательный центр', $html);
        self::assertStringContainsString('Заявка &lt;важная&gt;', $html);
        self::assertMatchesRegularExpression('~Первая строка<br>\s*Вторая строка~u', $html);
        self::assertStringContainsString('https://portal.test/?request=42&amp;from=mail', $html);
    }

    public function testMakesExistingLinksClickableWithoutAllowingBodyMarkup(): void
    {
        $html = EmailTemplate::render('Тема', '<script>alert(1)</script> https://files.test/a?x=1&y=2', 'https://portal.test/?request=1');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('href="https://files.test/a?x=1&amp;y=2"', $html);
    }
}

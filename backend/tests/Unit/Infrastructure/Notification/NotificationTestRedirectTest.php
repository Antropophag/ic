<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notification;

use App\Infrastructure\Notification\NotificationTestRedirect;
use PHPUnit\Framework\TestCase;

final class NotificationTestRedirectTest extends TestCase
{
    public function testPassesThroughUnchangedWhenRedirectIsNotConfigured(): void
    {
        $result = NotificationTestRedirect::apply('user@example.com', 'Иван Иванов', 'Тема', 'Тело', null);
        self::assertSame(['user@example.com', 'Тема', 'Тело'], $result);
    }

    public function testPassesThroughUnchangedWhenRedirectIsEmptyString(): void
    {
        $result = NotificationTestRedirect::apply('user@example.com', 'Иван Иванов', 'Тема', 'Тело', '');
        self::assertSame(['user@example.com', 'Тема', 'Тело'], $result);
    }

    public function testRedirectsAndPreservesOriginalRecipientForTraceability(): void
    {
        [$email, $subject, $body] = NotificationTestRedirect::apply(
            'user@example.com',
            'Иван Иванов',
            'Поступил отчёт испытаний',
            'Текст письма',
            'tester@shlz.ru',
        );

        self::assertSame('tester@shlz.ru', $email);
        self::assertStringContainsString('Иван Иванов', $subject);
        self::assertStringContainsString('user@example.com', $subject);
        self::assertStringContainsString('Поступил отчёт испытаний', $subject);
        self::assertStringContainsString('Иван Иванов', $body);
        self::assertStringContainsString('user@example.com', $body);
        self::assertStringContainsString('Текст письма', $body);
    }
}

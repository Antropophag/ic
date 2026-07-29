<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

final class NotificationTestRedirect
{
    /**
     * Тестовый контур: перенаправляет письмо на один ящик, не трогая
     * notification_outbox — там остаётся настоящий получатель (AUD-001/NTF),
     * подменяется только фактическая отправка. Пустой/отсутствующий
     * $redirectTo — обычная доставка без изменений.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function apply(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $body,
        ?string $redirectTo,
    ): array {
        if ($redirectTo === null || $redirectTo === '') {
            return [$recipientEmail, $subject, $body];
        }

        return [
            $redirectTo,
            "[Тест, настоящий получатель: {$recipientName} <{$recipientEmail}>] {$subject}",
            "Письмо адресовано: {$recipientName} <{$recipientEmail}>\n"
            . "(перенаправлено на тестовый ящик NOTIFICATION_TEST_REDIRECT_EMAIL)\n\n"
            . str_repeat('-', 40) . "\n\n"
            . $body,
        ];
    }
}

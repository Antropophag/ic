<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use RuntimeException;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class Mailer
{
    public function send(string $toEmail, string $toName, string $subject, string $body): void
    {
        $transport = Transport::fromDsn($this->dsn());
        $mailer = new SymfonyMailer($transport);

        $email = (new Email())
            ->from(new Address(self::env('MAIL_FROM_ADDRESS'), self::env('MAIL_FROM_NAME', '')))
            ->to(new Address($toEmail, $toName))
            ->subject($subject)
            ->text($body);

        $mailer->send($email);
    }

    private function dsn(): string
    {
        $secure = self::env('SMTP_SECURE', 'tls');
        $scheme = $secure === 'tls' ? 'smtp' : ($secure === 'ssl' ? 'smtps' : 'smtp');

        // Внутренний релей использует самоподписанный сертификат — проверка
        // отключена намеренно, как в уже настроенном корпоративном отправителе.
        return sprintf(
            '%s://%s:%s@%s:%s?verify_peer=0',
            $scheme,
            rawurlencode(self::env('SMTP_USERNAME')),
            rawurlencode(self::env('SMTP_PASSWORD')),
            self::env('SMTP_HOST'),
            self::env('SMTP_PORT', '587'),
        );
    }

    private static function env(string $name, ?string $default = null): string
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Required environment variable {$name} is missing");
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use RuntimeException;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class Mailer
{
    public function send(int $requestId, string $toEmail, string $toName, string $subject, string $body): void
    {
        $transport = $this->transport();
        $mailer = new SymfonyMailer($transport);

        $email = (new Email())
            ->from(new Address(self::env('MAIL_FROM_ADDRESS'), self::env('MAIL_FROM_NAME', '')))
            ->to(new Address($toEmail, $toName))
            ->subject($subject)
            ->text($body . "\n\nОткрыть заявку: " . RequestUrl::build($requestId))
            ->html(EmailTemplate::render($subject, $body, RequestUrl::build($requestId)));

        $mailer->send($email);
    }

    private function transport(): TransportInterface
    {
        $transport = Transport::fromDsn($this->dsn());
        if ($transport instanceof SmtpTransport) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout((float) self::positiveIntegerEnv('SMTP_TIMEOUT', 5));
            }
        }

        return $transport;
    }

    private function dsn(): string
    {
        $secure = self::env('SMTP_SECURE', 'tls');
        $scheme = $secure === 'tls' ? 'smtp' : ($secure === 'ssl' ? 'smtps' : 'smtp');

        // По умолчанию сертификат релея проверяется. Внутренний релей на
        // самоподписанном сертификате требует явного SMTP_VERIFY_PEER=0 —
        // отключение проверки никогда не подразумевается по умолчанию.
        $verifyPeer = self::env('SMTP_VERIFY_PEER', '1') === '1' ? '1' : '0';

        return sprintf(
            '%s://%s:%s@%s:%s?verify_peer=%s',
            $scheme,
            rawurlencode(self::env('SMTP_USERNAME')),
            rawurlencode(self::env('SMTP_PASSWORD')),
            self::env('SMTP_HOST'),
            self::env('SMTP_PORT', '587'),
            $verifyPeer,
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

    private static function positiveIntegerEnv(string $name, int $default): int
    {
        $value = self::env($name, (string) $default);
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < 1) {
            throw new RuntimeException("Environment variable {$name} must be a positive integer");
        }

        return $parsed;
    }
}

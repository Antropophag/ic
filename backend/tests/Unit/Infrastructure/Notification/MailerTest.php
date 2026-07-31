<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notification;

use App\Infrastructure\Notification\Mailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

final class MailerTest extends TestCase
{
    /** @var array<string, false|string> */
    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
        $this->originalEnvironment = [];
        parent::tearDown();
    }

    public function testAppliesConfiguredTimeoutToRealSmtpTransport(): void
    {
        $this->configureSmtpEnvironment();
        $this->setEnvironment('SMTP_TIMEOUT', '7');

        $method = new \ReflectionMethod(Mailer::class, 'transport');
        $transport = $method->invoke(new Mailer());

        self::assertInstanceOf(SmtpTransport::class, $transport);
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame(7.0, $stream->getTimeout());
    }

    public function testUsesFiveSecondTimeoutByDefault(): void
    {
        $this->configureSmtpEnvironment();
        $this->setEnvironment('SMTP_TIMEOUT', false);

        $method = new \ReflectionMethod(Mailer::class, 'transport');
        $transport = $method->invoke(new Mailer());
        self::assertInstanceOf(SmtpTransport::class, $transport);
        $stream = $transport->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame(5.0, $stream->getTimeout());
    }

    private function configureSmtpEnvironment(): void
    {
        $this->setEnvironment('SMTP_HOST', 'smtp.example.invalid');
        $this->setEnvironment('SMTP_PORT', '587');
        $this->setEnvironment('SMTP_SECURE', 'tls');
        $this->setEnvironment('SMTP_USERNAME', 'user');
        $this->setEnvironment('SMTP_PASSWORD', 'password');
    }

    private function setEnvironment(string $name, false|string $value): void
    {
        if (!array_key_exists($name, $this->originalEnvironment)) {
            $this->originalEnvironment[$name] = getenv($name);
        }
        $value === false ? putenv($name) : putenv("{$name}={$value}");
    }
}

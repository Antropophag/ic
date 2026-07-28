<?php

declare(strict_types=1);

namespace App\Infrastructure\Bitrix;

use Closure;
use JsonException;
use RuntimeException;

final class NativeBitrixTransport implements BitrixTransport
{
    private const ALLOWED_METHODS = ['lists.field.get', 'lists.element.get'];

    /** @var Closure(string, resource): (string|false) */
    private readonly Closure $fetch;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $maxAttempts = 3,
        ?Closure $fetch = null,
        private readonly int $retryDelayMilliseconds = 250,
    ) {
        if (!str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('Bitrix24 webhook URL must use HTTPS.');
        }
        $this->fetch = $fetch ?? static fn (string $url, $context): string|false =>
            @file_get_contents($url, false, $context);
    }

    public function call(string $method, array $parameters = []): array
    {
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new RuntimeException('Bitrix24 method is not allowed by the read-only transport.');
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            $response = ($this->fetch)(rtrim($this->baseUrl, '/') . "/{$method}.json", $context);
            if ($response !== false) {
                try {
                    $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $payload = null;
                }
                if (!is_array($payload)) {
                    if ($attempt === $this->maxAttempts) {
                        break;
                    }
                } else {
                    $code = is_string($payload['error'] ?? null) ? $payload['error'] : null;
                    if ($code === null) {
                        return $payload;
                    }
                    if ($code !== 'OVERLOAD_LIMIT') {
                        throw new RuntimeException("Bitrix24 API error: {$code}");
                    }
                }
            }

            if ($attempt < $this->maxAttempts && $this->retryDelayMilliseconds > 0) {
                usleep($this->retryDelayMilliseconds * 1000 * $attempt);
            }
        }

        throw new RuntimeException('Bitrix24 request failed after retries.');
    }
}

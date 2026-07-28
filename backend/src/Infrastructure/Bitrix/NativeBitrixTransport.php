<?php

declare(strict_types=1);

namespace App\Infrastructure\Bitrix;

use RuntimeException;

final class NativeBitrixTransport implements BitrixTransport
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $maxAttempts = 3,
    ) {
        if (!str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('Bitrix24 webhook URL must use HTTPS.');
        }
    }

    public function call(string $method, array $parameters = []): array
    {
        if (!preg_match('/^[a-z0-9.]+$/', $method)) {
            throw new RuntimeException('Invalid Bitrix24 method name.');
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            $response = @file_get_contents(rtrim($this->baseUrl, '/') . "/{$method}.json", false, $context);
            if ($response !== false) {
                $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new RuntimeException('Bitrix24 returned an invalid response.');
                }
                $code = is_string($payload['error'] ?? null) ? $payload['error'] : null;
                if ($code === null) {
                    return $payload;
                }
                if ($code !== 'OVERLOAD_LIMIT') {
                    throw new RuntimeException("Bitrix24 API error: {$code}");
                }
            }

            if ($attempt < $this->maxAttempts) {
                usleep(250000 * $attempt);
            }
        }

        throw new RuntimeException('Bitrix24 request failed after retries.');
    }
}

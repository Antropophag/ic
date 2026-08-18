<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Ai\AiFeatureUnavailable;

final class NativeOpenWebUiTransport implements OpenWebUiTransport
{
    private ?OpenWebUiSocketSession $socket = null;
    private ?int $deadlineNanoseconds = null;

    /** @param (\Closure(string, string, float): ?string)|null $persistedCompletionReader */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly float $timeoutSeconds = 45.0,
        private readonly float $connectTimeoutSeconds = 10.0,
        private readonly OpenWebUiSocketSessionFactory $socketFactory = new ElephantOpenWebUiSocketSessionFactory(),
        private readonly float $completionTimeoutSeconds = 300.0,
        private readonly ?\Closure $persistedCompletionReader = null,
    ) {
    }

    public function beginOperation(): void
    {
        $this->deadlineNanoseconds = hrtime(true) + (int) round($this->completionTimeoutSeconds * 1_000_000_000);
    }

    public function socketSessionId(): string
    {
        $remaining = $this->remainingSeconds();
        $this->disconnect();
        $this->socket = $this->socketFactory->create(
            $this->baseUrl,
            $this->token,
            min($this->connectTimeoutSeconds, $remaining),
        );
        try {
            return $this->socket->connect();
        } catch (\Throwable $error) {
            $this->disconnect();
            throw new AiFeatureUnavailable('ЛИЗА временно недоступна. Основная работа с заявкой не затронута.', 0, $error);
        }
    }

    public function uploadFile(string $name, string $mimeType, string $path): array
    {
        $remaining = $this->remainingSeconds();
        $handle = curl_init(rtrim($this->baseUrl, '/') . '/api/v1/files/');
        if ($handle === false) {
            throw new AiFeatureUnavailable('ЛИЗА не смогла загрузить техническое задание. Повторите попытку.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new \CURLFile($path, $mimeType, $name)],
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $this->token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round(min($this->connectTimeoutSeconds, $remaining) * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) round(min($this->timeoutSeconds, $remaining) * 1000)),
        ]);
        try {
            $response = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }
        if (!is_string($response) || $status >= 300) {
            throw new AiFeatureUnavailable(
                'ЛИЗА не смогла загрузить техническое задание. Повторите попытку.',
                0,
                new \RuntimeException('Open WebUI file upload failed: HTTP ' . $status),
            );
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !is_string($decoded['id'] ?? null) || $decoded['id'] === '') {
            throw new AiFeatureUnavailable('ЛИЗА не смогла загрузить техническое задание. Повторите попытку.');
        }
        return $decoded;
    }

    public function deleteFile(string $fileId): void
    {
        $this->request(
            'DELETE',
            rtrim($this->baseUrl, '/') . '/api/v1/files/' . rawurlencode($fileId),
            authenticated: true,
        );
    }

    public function submit(array $payload): void
    {
        $this->request('POST', rtrim($this->baseUrl, '/') . '/api/chat/completions', json_encode($payload, JSON_THROW_ON_ERROR), 'application/json', true);
    }

    public function createChat(array $chat): string
    {
        $response = $this->request(
            'POST',
            rtrim($this->baseUrl, '/') . '/api/v1/chats/new',
            json_encode(['chat' => $chat], JSON_THROW_ON_ERROR),
            'application/json',
            true,
        );
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !is_string($decoded['id'] ?? null) || $decoded['id'] === '') {
            throw new AiFeatureUnavailable('ЛИЗА временно недоступна. Основная работа с заявкой не затронута.');
        }

        return $decoded['id'];
    }

    public function completion(string $chatId, string $messageId): string
    {
        if ($this->socket === null) {
            throw new \LogicException('Socket.IO session was not initialized.');
        }
        $content = '';
        $generationInactive = false;
        $nextPersistedCheck = hrtime(true);
        while (($remaining = $this->remainingSeconds(false)) > 0) {
            try {
                $event = $this->socket->waitForEvents(min($generationInactive ? 0.25 : 2.0, $remaining));
            } catch (\Throwable $error) {
                throw new AiFeatureUnavailable('ЛИЗА временно недоступна. Основная работа с заявкой не затронута.', 0, $error);
            }
            $now = hrtime(true);
            if ($now >= $nextPersistedCheck) {
                $persisted = $this->persistedCompletion($chatId, $messageId, $this->remainingSeconds(false));
                if ($persisted !== null) {
                    return $persisted;
                }
                $nextPersistedCheck = $now + (int) (($generationInactive ? 0.25 : 2.0) * 1_000_000_000);
            }
            if ($event === null) {
                usleep(100_000);
                continue;
            }
            if (!$this->belongsTo($event, $chatId, $messageId)) {
                continue;
            }
            $data = $event['data'] ?? [];
            $type = (string) ($data['type'] ?? $event['type'] ?? '');
            $detail = is_array($data['data'] ?? null) ? $data['data'] : $data;
            if ($type === 'chat:active' && ($detail['active'] ?? null) === false) {
                $generationInactive = true;
                $nextPersistedCheck = hrtime(true);
                continue;
            }
            if (is_string($detail['content'] ?? null)) {
                // Open WebUI streams chat:completion content as deltas. Replacing
                // the value here leaves only the final token (often just `}`).
                $content .= $detail['content'];
            }
            if ($type === 'chat:completion' && ($detail['done'] ?? false) === true) {
                if ($content === '') {
                    throw new AiFeatureUnavailable('ЛИЗА завершила запрос без текста ответа.');
                }
                return $content;
            }
        }
        throw new AiFeatureUnavailable($generationInactive
            ? 'ЛИЗА завершила обработку без ответа. Повторите попытку позже.'
            : 'ЛИЗА не завершила анализ за отведённое время. Повторите попытку позже.');
    }

    private function persistedCompletion(string $chatId, string $messageId, float $remainingSeconds): ?string
    {
        if ($this->persistedCompletionReader !== null) {
            return ($this->persistedCompletionReader)($chatId, $messageId, $remainingSeconds);
        }
        try {
            $response = $this->request(
                'GET',
                rtrim($this->baseUrl, '/') . '/api/v1/chats/' . rawurlencode($chatId),
                authenticated: true,
                timeoutSeconds: max(0.001, min($this->timeoutSeconds, $remainingSeconds)),
            );
        } catch (AiFeatureUnavailable) {
            // The WebSocket remains authoritative while the persisted chat is
            // temporarily unavailable or has not been written yet.
            return null;
        }
        $decoded = json_decode($response, true);
        $message = is_array($decoded)
            ? ($decoded['chat']['history']['messages'][$messageId] ?? null)
            : null;
        if (!is_array($message) || ($message['done'] ?? false) !== true) {
            return null;
        }
        $content = $message['content'] ?? null;
        return is_string($content) && $content !== '' ? $content : null;
    }

    public function disconnect(): void
    {
        if ($this->socket !== null) {
            try {
                $this->socket->disconnect();
            } catch (\Throwable) {
                // Cleanup must not replace a completed response or the controlled request error.
            } finally {
                $this->socket = null;
            }
        }
    }

    /** @param array<string, mixed> $event */
    private function belongsTo(array $event, string $chatId, string $messageId): bool
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        return ($event['chat_id'] ?? $data['chat_id'] ?? null) === $chatId
            && ($event['message_id'] ?? $data['message_id'] ?? null) === $messageId;
    }

    private function request(
        string $method,
        string $url,
        ?string $body = null,
        ?string $contentType = null,
        bool $authenticated = false,
        ?float $timeoutSeconds = null,
    ): string {
        $timeoutSeconds = min($timeoutSeconds ?? $this->timeoutSeconds, $this->remainingSeconds());
        $headers = ['Accept: application/json, text/plain, */*'];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        if ($authenticated) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ]]);
        $result = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($result === false || preg_match('/\s(3\d\d|[45]\d\d)\s/', $statusLine) === 1) {
            throw new AiFeatureUnavailable(
                'ЛИЗА временно недоступна. Основная работа с заявкой не затронута.',
                0,
                new \RuntimeException('Open WebUI request failed: ' . ($statusLine !== '' ? $statusLine : 'no response')),
            );
        }
        return $result;
    }

    private function remainingSeconds(bool $throwWhenExpired = true): float
    {
        if ($this->deadlineNanoseconds === null) {
            throw new \LogicException('AI operation deadline was not initialized.');
        }
        $remaining = ($this->deadlineNanoseconds - hrtime(true)) / 1_000_000_000;
        if ($remaining <= 0 && $throwWhenExpired) {
            throw new AiFeatureUnavailable('ЛИЗА не завершила анализ за отведённое время. Повторите попытку позже.');
        }
        return max(0.0, $remaining);
    }
}

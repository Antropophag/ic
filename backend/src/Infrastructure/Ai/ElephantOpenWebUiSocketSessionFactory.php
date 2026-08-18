<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use ElephantIO\Client;

final class ElephantOpenWebUiSocketSessionFactory implements OpenWebUiSocketSessionFactory
{
    public function create(string $baseUrl, string $token, float $connectTimeoutSeconds): OpenWebUiSocketSession
    {
        $engine = new ElephantOpenWebUiSocketEngine($baseUrl, [
            'transport' => 'websocket',
            'transports' => ['websocket'],
            'sio_path' => 'ws/socket.io',
            'auth' => ['token' => $token],
            'timeout' => $connectTimeoutSeconds,
            'persistent' => false,
        ]);

        return new ElephantOpenWebUiSocketSession(new Client($engine), $engine);
    }
}

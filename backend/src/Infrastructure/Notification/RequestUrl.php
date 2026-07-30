<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

final class RequestUrl
{
    public static function build(int $requestId): string
    {
        $base = getenv('APP_PUBLIC_URL');
        if ($base === false || trim($base) === '') {
            throw new \RuntimeException('Required environment variable APP_PUBLIC_URL is missing');
        }

        return rtrim(trim($base), '/') . '/?request=' . $requestId;
    }
}

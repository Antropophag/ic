<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

final class DocumentDownloadUrl
{
    public static function build(string $token): string
    {
        $base = getenv('APP_PUBLIC_URL');
        if ($base === false || $base === '') {
            throw new \RuntimeException('Required environment variable APP_PUBLIC_URL is missing');
        }

        return rtrim($base, '/') . '/api/v1/document-links/' . $token . '/download';
    }
}

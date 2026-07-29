<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

final class DocumentDownloadUrl
{
    public static function build(string $token): string
    {
        $base = rtrim((getenv('APP_PUBLIC_URL') ?: 'http://localhost:8080'), '/');

        return $base . '/api/v1/document-links/' . $token . '/download';
    }
}

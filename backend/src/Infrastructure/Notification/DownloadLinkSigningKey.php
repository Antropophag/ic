<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

final class DownloadLinkSigningKey
{
    public static function get(): string
    {
        $key = getenv('DOWNLOAD_LINK_SIGNING_KEY');
        if (
            $key === false
            || strlen($key) < 32
            || str_starts_with($key, 'replace-with-')
        ) {
            throw new InvalidDownloadLinkConfiguration(
                'DOWNLOAD_LINK_SIGNING_KEY must be a non-placeholder value containing at least 32 characters',
            );
        }

        return $key;
    }
}

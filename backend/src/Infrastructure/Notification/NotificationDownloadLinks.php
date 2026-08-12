<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Clock;
use App\Infrastructure\Document\DocumentDownloadUrl;
use yii\db\Connection;

final class NotificationDownloadLinks
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param list<array{label: string, documentVersionId: int}> $links */
    public function appendToBody(int $notificationId, string $body, array $links): string
    {
        foreach ($links as $link) {
            $versionId = $link['documentVersionId'];
            $token = $this->token($notificationId, $versionId);
            $this->db->createCommand(
                'INSERT IGNORE INTO {{%document_download_links}} '
                . '(document_version_id, token_hash, created_at) VALUES (:version_id, :token_hash, :created_at)',
                [
                    ':version_id' => $versionId,
                    ':token_hash' => hash('sha256', $token),
                    ':created_at' => Clock::now(),
                ],
            )->execute();
            $body .= "\nСсылка на {$link['label']}: " . DocumentDownloadUrl::build($token);
        }

        return $body;
    }

    private function token(int $notificationId, int $documentVersionId): string
    {
        $key = DownloadLinkSigningKey::get();
        return hash_hmac('sha256', "notification-download:v1:{$notificationId}:{$documentVersionId}", $key);
    }
}

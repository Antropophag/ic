<?php

declare(strict_types=1);

use yii\db\Migration;
use App\Infrastructure\Notification\DownloadLinkSigningKey;
use App\Infrastructure\Notification\NotificationOutboxCredentialCleanup;

/**
 * F074 / ACL-005: move document references to semantic outbox payloads and
 * revoke credentials that were previously persisted in notification bodies.
 */
final class m260813_000001_secure_notification_download_links extends Migration
{
    public function safeUp(): void
    {
        DownloadLinkSigningKey::get();
        $this->addColumn('{{%notification_outbox}}', 'payload_json', $this->json()->null()->after('body'));

        (new NotificationOutboxCredentialCleanup($this->db))->run();
        $this->alterColumn('{{%notification_outbox}}', 'payload_json', $this->json()->notNull());
    }

    public function safeDown(): void
    {
        throw new \RuntimeException(
            static::class . ' is irreversible: revoked plaintext bearer credentials must not be restored.',
        );
    }
}

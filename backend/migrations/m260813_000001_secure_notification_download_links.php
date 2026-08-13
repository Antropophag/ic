<?php

declare(strict_types=1);

use yii\base\NotSupportedException;
use yii\db\Migration;
use App\Infrastructure\Notification\DownloadLinkSigningKey;
use App\Infrastructure\Notification\NotificationOutboxCredentialCleanup;

/**
 * F074 / ACL-005: move document references to semantic outbox payloads and
 * preserve their hash-only download records for potentially delivered links.
 */
final class m260813_000001_secure_notification_download_links extends Migration
{
    public function safeUp(): void
    {
        DownloadLinkSigningKey::get();
        if (!$this->hasPayloadColumn()) {
            $this->addColumn('{{%notification_outbox}}', 'payload_json', $this->json()->null()->after('body'));
            $this->db->schema->refreshTableSchema('notification_outbox');
        }

        (new NotificationOutboxCredentialCleanup($this->db))->run();
        if ($this->payloadColumnAllowsNull()) {
            $this->db->createCommand()->update(
                '{{%notification_outbox}}',
                ['payload_json' => ['documentLinks' => []]],
                ['payload_json' => null],
            )->execute();
            $this->alterColumn('{{%notification_outbox}}', 'payload_json', $this->json()->notNull());
            $this->db->schema->refreshTableSchema('notification_outbox');
        }
    }

    public function safeDown(): void
    {
        throw new NotSupportedException(
            static::class . ' is irreversible: scrubbed plaintext bearer credentials must not be restored.',
        );
    }

    private function hasPayloadColumn(): bool
    {
        $table = $this->db->schema->getTableSchema('{{%notification_outbox}}', true);
        return $table !== null && isset($table->columns['payload_json']);
    }

    private function payloadColumnAllowsNull(): bool
    {
        $table = $this->db->schema->getTableSchema('{{%notification_outbox}}', true);
        return $table !== null && isset($table->columns['payload_json']) && $table->columns['payload_json']->allowNull;
    }
}

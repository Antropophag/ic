<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Infrastructure\Clock;
use App\Infrastructure\Notification\NotificationOutboxCredentialCleanup;
use App\Infrastructure\Notification\NotificationDownloadLinks;
use App\Infrastructure\Notification\InvalidNotificationPayload;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;
use yii\db\Migration;

final class NotificationOutboxCredentialCleanupTest extends IntegrationTestCase
{
    public function testRerunPreservesMigratedPayloadAndMigratesRemainingRowsInBatches(): void
    {
        $versionId = $this->createVersion();
        $preservedPayload = ['documentLinks' => [['label' => 'заключение', 'documentVersionId' => $versionId]]];
        $alreadyMigrated = $this->createOutbox('Уже безопасно', $preservedPayload);
        $legacyIds = [];
        $tokens = [];
        for ($index = 0; $index < 3; $index++) {
            $token = bin2hex(random_bytes(32));
            $tokens[] = $token;
            $this->createToken($versionId, $token);
            $legacyIds[] = $this->createOutbox(
                "Legacy {$index}\nСсылка на отчёт: http://legacy.invalid/api/v1/document-links/{$token}/download",
                ['documentLinks' => []],
            );
        }

        $cleanup = new NotificationOutboxCredentialCleanup($this->db(), 2);
        $cleanup->run();
        $firstState = $this->outboxState(array_merge([$alreadyMigrated], $legacyIds));
        $cleanup->run();

        self::assertSame($firstState, $this->outboxState(array_merge([$alreadyMigrated], $legacyIds)));
        self::assertSame($preservedPayload, $this->decodePayload($firstState[$alreadyMigrated]['payload_json']));
        foreach ($legacyIds as $index => $id) {
            self::assertStringNotContainsString($tokens[$index], $firstState[$id]['body']);
            self::assertSame(
                ['documentLinks' => [['label' => 'отчёт', 'documentVersionId' => $versionId]]],
                $this->decodePayload($firstState[$id]['payload_json']),
            );
            self::assertSame(0, (int) $this->scalar(
                'SELECT COUNT(*) FROM {{%document_download_links}} WHERE token_hash = :hash',
                [':hash' => hash('sha256', $tokens[$index])],
            ));
        }
    }

    public function testFailureBetweenOutboxUpdateAndTokenDeletionRollsBackAndRerunSucceeds(): void
    {
        $versionId = $this->createVersion();
        $token = bin2hex(random_bytes(32));
        $this->createToken($versionId, $token);
        $id = $this->createOutbox(
            "Legacy\nСсылка на отчёт: http://legacy.invalid/api/v1/document-links/{$token}/download",
            ['documentLinks' => []],
        );
        $before = $this->outboxState([$id]);

        try {
            (new NotificationOutboxCredentialCleanup(
                $this->db(),
                1,
                static fn (): never => throw new RuntimeException('Controlled interruption'),
            ))->run();
            self::fail('Controlled cleanup interruption did not happen.');
        } catch (RuntimeException $error) {
            self::assertSame('Controlled interruption', $error->getMessage());
        }

        self::assertSame($before, $this->outboxState([$id]));
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%document_download_links}} WHERE token_hash = :hash',
            [':hash' => hash('sha256', $token)],
        ));

        (new NotificationOutboxCredentialCleanup($this->db(), 1))->run();
        $after = $this->outboxState([$id])[$id];
        self::assertStringNotContainsString($token, $after['body']);
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%document_download_links}} WHERE token_hash = :hash',
            [':hash' => hash('sha256', $token)],
        ));
    }

    public function testMigrationCanRunAgainWhenSchemaAlreadyExists(): void
    {
        $path = dirname(__DIR__, 3) . '/migrations/m260813_000001_secure_notification_download_links.php';
        require_once $path;
        $class = pathinfo($path, PATHINFO_FILENAME);
        $loaded = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(Migration::class, $loaded);
        $loaded->db = $this->db();

        $loaded->safeUp();

        $schema = $this->db()->schema->getTableSchema('{{%notification_outbox}}', true);
        self::assertNotNull($schema);
        self::assertArrayHasKey('payload_json', $schema->columns);
        self::assertFalse($schema->columns['payload_json']->allowNull);
    }

    public function testHmacContextsAreSeparatedAndSoftDeletedVersionsAreRejected(): void
    {
        $firstVersion = $this->createVersion();
        $secondVersion = $this->createVersion();
        $links = new NotificationDownloadLinks($this->db());
        $firstBody = $links->appendToBody(1, 'Body', [['label' => 'отчёт', 'documentVersionId' => $firstVersion]]);
        $secondBody = $links->appendToBody(12, 'Body', [['label' => 'отчёт', 'documentVersionId' => $secondVersion]]);
        preg_match('~/document-links/([a-f0-9]{64})/download~', $firstBody, $firstMatch);
        preg_match('~/document-links/([a-f0-9]{64})/download~', $secondBody, $secondMatch);
        self::assertNotSame($firstMatch[1], $secondMatch[1]);

        $this->db()->createCommand()->update(
            '{{%request_document_versions}}',
            ['deleted_at' => Clock::now()],
            ['id' => $firstVersion],
        )->execute();
        $before = (int) $this->scalar('SELECT COUNT(*) FROM {{%document_download_links}}');
        $this->expectException(InvalidNotificationPayload::class);
        try {
            $links->appendToBody(2, 'Body', [['label' => 'отчёт', 'documentVersionId' => $firstVersion]]);
        } finally {
            self::assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM {{%document_download_links}}'));
        }
    }

    public function testSoftDeletedDocumentIsRejectedBeforeCredentialCreation(): void
    {
        $versionId = $this->createVersion();
        $this->db()->createCommand(
            'UPDATE {{%request_documents}} d JOIN {{%request_document_versions}} v ON v.document_id = d.id '
            . 'SET d.deleted_at = :deleted_at WHERE v.id = :version_id',
            [':deleted_at' => Clock::now(), ':version_id' => $versionId],
        )->execute();

        $this->expectException(InvalidNotificationPayload::class);
        (new NotificationDownloadLinks($this->db()))->appendToBody(
            3,
            'Body',
            [['label' => 'отчёт', 'documentVersionId' => $versionId]],
        );
    }

    private function createVersion(): int
    {
        $userId = $this->createUser(uniqid('cleanup.', true), 'Cleanup Test');
        $now = Clock::now();
        $this->db()->createCommand()->insert('{{%requests}}', [
            'number' => random_int(1_000_000, 9_999_999), 'initiator_id' => $userId, 'status' => 'registered',
            'product_name' => 'Тест', 'manufacturer' => 'Тест', 'supplier' => 'Тест', 'sample_quantity' => 1,
            'test_method' => 'Тест', 'created_at' => $now, 'updated_at' => $now,
        ])->execute();
        $requestId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId, 'document_type' => 'report', 'title' => 'report.pdf',
            'created_by' => $userId, 'created_at' => $now,
        ])->execute();
        $documentId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $documentId, 'version' => 1, 'storage_key' => bin2hex(random_bytes(32)), 'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
            'uploaded_by' => $userId, 'created_at' => $now,
        ])->execute();
        return (int) $this->db()->getLastInsertID();
    }

    private function createToken(int $versionId, string $token): void
    {
        $this->db()->createCommand()->insert('{{%document_download_links}}', [
            'document_version_id' => $versionId, 'token_hash' => hash('sha256', $token), 'created_at' => Clock::now(),
        ])->execute();
    }

    /** @param array{documentLinks: array<mixed>}|null $payload */
    private function createOutbox(string $body, ?array $payload): int
    {
        $requestId = (int) $this->scalar('SELECT request_id FROM {{%request_documents}} ORDER BY id DESC LIMIT 1');
        $now = Clock::now();
        $this->db()->createCommand()->insert('{{%notification_outbox}}', [
            'request_id' => $requestId, 'event_type' => 'test.cleanup', 'recipient_email' => 'cleanup@example.invalid',
            'recipient_name' => 'Cleanup', 'subject' => 'Cleanup', 'body' => $body, 'payload_json' => $payload,
            'status' => 'failed', 'attempts' => 5, 'next_attempt_at' => $now, 'created_at' => $now,
        ])->execute();
        return (int) $this->db()->getLastInsertID();
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{body: string, payload_json: array<string, mixed>|string|null, status: string}>
     */
    private function outboxState(array $ids): array
    {
        $rows = $this->db()->createCommand(
            'SELECT id, body, payload_json, status FROM {{%notification_outbox}} WHERE id IN ('
            . implode(',', array_map('intval', $ids)) . ') ORDER BY id',
        )->queryAll();
        $state = [];
        foreach ($rows as $row) {
            $state[(int) $row['id']] = ['body' => $row['body'], 'payload_json' => $row['payload_json'], 'status' => $row['status']];
        }
        return $state;
    }

    /** @return array{documentLinks: array<mixed>} */
    private function decodePayload(mixed $payload): array
    {
        return is_array($payload) ? $payload : json_decode((string) $payload, true, flags: JSON_THROW_ON_ERROR);
    }
}

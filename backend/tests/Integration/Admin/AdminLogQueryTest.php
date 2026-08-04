<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Infrastructure\Admin\AuditQuery;
use App\Infrastructure\Admin\NotificationQuery;
use Tests\Integration\IntegrationTestCase;

final class AdminLogQueryTest extends IntegrationTestCase
{
    public function testAuditCursorWhitelistDeniedAndUnknownFallback(): void
    {
        $actor = $this->createUser('audit.admin', 'Audit Admin');
        $requestId = $this->request($actor);
        $this->audit($actor, $requestId, 'request.rejected', '2026-08-04 10:00:00.000000', [
            'from_status' => 'in_progress', 'to_status' => 'rejected', 'reason' => 'secret',
        ]);
        $this->audit($actor, $requestId, 'request.start_denied', '2026-08-04 10:00:00.000000', []);
        $this->audit($actor, $requestId, 'future.secret_event', '2026-08-04 09:00:00.000000', ['password' => 'secret']);

        $query = new AuditQuery($this->db());
        $first = $query->findPage($this->auditFilters(['limit' => 2]));
        self::assertSame(['request.start_denied', 'request.rejected'], array_column($first['items'], 'eventType'));
        self::assertSame('denied', $first['items'][0]['result']);
        self::assertArrayNotHasKey('reason', $first['items'][1]['details']);
        $second = $query->findPage($this->auditFilters(['cursor' => $first['nextCursor']]));
        self::assertSame('Системное событие', $second['items'][0]['title']);
        self::assertSame([], $second['items'][0]['details']);
    }

    public function testNotificationFiltersHealthSafeErrorAndNoBody(): void
    {
        $actor = $this->createUser('notify.admin', 'Notify Admin');
        $requestId = $this->request($actor);
        $this->notification($requestId, 'sending', 1, '2020-01-01 00:00:00.000000', '/srv/app password=secret');
        $this->notification($requestId, 'failed', 5, '2026-08-04 10:00:00.000000', 'SMTP credentials secret');
        $query = new NotificationQuery($this->db());
        $page = $query->findPage($this->notificationFilters(['problematic' => '1']));
        self::assertCount(2, $page['items']);
        self::assertSame('failed', $page['items'][0]['health']);
        self::assertSame('Ошибка SMTP', $page['items'][0]['lastError']);
        self::assertArrayNotHasKey('body', $page['items'][0]);
        self::assertSame('stale', $page['items'][1]['health']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function auditFilters(array $overrides = []): array
    {
        return array_replace(['actorId' => null, 'eventType' => null, 'entityType' => null, 'entityId' => null,
            'requestId' => null, 'result' => 'all', 'dateFrom' => null, 'dateTo' => null, 'limit' => 50,
            'cursor' => null], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function notificationFilters(array $overrides = []): array
    {
        return array_replace(['status' => null, 'requestId' => null, 'eventType' => null, 'recipient' => null,
            'dateFrom' => null, 'dateTo' => null, 'problematic' => null, 'limit' => 50, 'cursor' => null], $overrides);
    }

    private function request(int $initiator): int
    {
        $this->db()->createCommand()->insert('{{%requests}}', ['number' => random_int(800000, 899999),
            'initiator_id' => $initiator, 'status' => 'registered', 'product_name' => 'Test', 'manufacturer' => 'Test',
            'supplier' => 'Test', 'sample_quantity' => 1, 'test_method' => 'Test',
            'created_at' => '2026-08-04 08:00:00.000000', 'updated_at' => '2026-08-04 08:00:00.000000'])->execute();
        return (int) $this->db()->getLastInsertID();
    }

    /** @param array<string, mixed> $payload */
    private function audit(int $actor, int $requestId, string $type, string $at, array $payload): void
    {
        $this->db()->createCommand()->insert('{{%audit_events}}', ['event_type' => $type, 'entity_type' => 'request',
            'entity_id' => $requestId, 'actor_id' => $actor, 'rule_id' => 'TEST-001', 'payload_json' => $payload,
            'created_at' => $at])->execute();
    }

    private function notification(int $requestId, string $status, int $attempts, string $at, string $error): void
    {
        $this->db()->createCommand()->insert('{{%notification_outbox}}', ['request_id' => $requestId,
            'event_type' => 'future.event', 'recipient_email' => 'safe@example.invalid', 'recipient_name' => 'Safe User',
            'subject' => 'Safe subject', 'body' => '<b>SECRET BODY</b>', 'status' => $status, 'attempts' => $attempts,
            'next_attempt_at' => $at, 'last_error' => $error, 'created_at' => $at])->execute();
    }
}

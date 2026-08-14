<?php

declare(strict_types=1);

namespace Tests\Integration\Document;

use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\ReportDeletionDenied;
use App\Domain\Request\ReportDenied;
use App\Domain\Request\RequestNotFound;
use App\Infrastructure\Clock;
use App\Infrastructure\Document\DocumentRepository;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Notification\NotificationOutboxProcessor;
use App\Infrastructure\Notification\NotificationOutboxCredentialCleanup;
use App\Infrastructure\Request\RequestQuery;
use Tests\Integration\IntegrationTestCase;

final class DocumentRepositoryTest extends IntegrationTestCase
{
    private ?string $storageRoot = null;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        // parent::tearDown() откатывает транзакцию теста — должен выполниться
        // даже если очистка временных файлов ниже упадёт, иначе следующий
        // тест унаследует незакоммиченное состояние текущего.
        try {
            foreach ($this->tempFiles as $path) {
                if (is_file($path)) {
                    // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam-created fixture under sys_get_temp_dir()
                    unlink($path);
                }
            }
            if ($this->storageRoot !== null && is_dir($this->storageRoot)) {
                $this->removeDirectory($this->storageRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function storage(): DocumentStorage
    {
        if ($this->storageRoot === null) {
            $this->storageRoot = sys_get_temp_dir() . '/ic-integration-' . bin2hex(random_bytes(8));
            mkdir($this->storageRoot, 0700, true);
        }
        return new DocumentStorage($this->storageRoot);
    }

    /** @return array{path: string, size: int, mime: string, name: string} */
    private function tempPdf(string $content = '%PDF-1.4 integration test'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ic-report-');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return ['path' => $path, 'size' => strlen($content), 'mime' => 'application/pdf', 'name' => 'report.pdf'];
    }

    /** @return array<string, mixed> */
    private function createInProgressRequestWithExecutor(int $initiatorId, int $executorId, string $marker): array
    {
        $input = new CreateRequestInput();
        $input->productName = "Изделие {$marker}";
        $input->manufacturer = 'Завод';
        $input->supplier = 'Поставщик';
        $input->sampleQuantity = 1;
        $input->testMethod = 'Интеграционный тест';
        $request = (new \App\Application\Request\UseCase\CreateRequest(new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($this->db())))->execute($input->toCommand($initiatorId))->toArray();
        $requestId = (int) $request['id'];
        $now = Clock::now();

        $this->db()->createCommand()->update('{{%requests}}', ['status' => 'in_progress'], ['id' => $requestId])->execute();
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'executor',
            'user_id' => $executorId,
            'assigned_by' => $initiatorId,
            'valid_from' => $now,
        ])->execute();

        return $this->db()->createCommand(
            'SELECT * FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryOne();
    }

    public function testReportUploadTransitionsStatusAndNotifiesOnlyActiveExperts(): void
    {
        $initiator = $this->createUser('dev.it.doc.initiator1', 'Инициатор');
        $executor = $this->createUser('dev.it.doc.executor1', 'Исполнитель');
        $outsider = $this->createUser('dev.it.doc.outsider0', 'Посторонний сотрудник');
        $activeExpert = $this->createUser('dev.it.doc.expert1', 'Активный эксперт', 'active.expert@example.invalid');
        $this->grantRole($activeExpert, 'expert');
        $inactiveExpert = $this->createUser(
            'dev.it.doc.expert2',
            'Неактивный эксперт',
            'inactive.expert@example.invalid',
            false,
        );
        $this->grantRole($inactiveExpert, 'expert');

        $request = $this->createInProgressRequestWithExecutor($initiator, $executor, 'report-upload');
        $requestId = (int) $request['id'];
        $file = $this->tempPdf();

        $repository = new DocumentRepository($this->db(), $this->storage());
        $result = $repository->uploadReport($requestId, $executor, $file['name'], $file['mime'], $file['size'], $file['path']);

        self::assertSame('opinion_preparation', $result['status']);
        self::assertSame((int) $request['lock_version'] + 1, $result['lockVersion']);

        $newStatus = $this->scalar('SELECT status FROM {{%requests}} WHERE id = :id', [':id' => $requestId]);
        self::assertSame('opinion_preparation', $newStatus);

        $activeNotified = $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id "
            . "AND event_type = 'request.report_uploaded' AND recipient_email = 'active.expert@example.invalid'",
            [':id' => $requestId],
        );
        self::assertSame(1, (int) $activeNotified);

        $inactiveNotified = $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id "
            . "AND event_type = 'request.report_uploaded' AND recipient_email = 'inactive.expert@example.invalid'",
            [':id' => $requestId],
        );
        self::assertSame(0, (int) $inactiveNotified);

        $persistedNotification = $this->db()->createCommand(
            "SELECT n.* FROM {{%notification_outbox}} n WHERE n.request_id = :id "
            . "AND n.event_type = 'request.report_uploaded' AND n.recipient_email = 'active.expert@example.invalid'",
            [':id' => $requestId],
        )->queryOne();
        self::assertNotFalse($persistedNotification);
        $persistedRepresentation = json_encode(
            $persistedNotification,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        self::assertFalse(
            $this->containsActiveDownloadCredential($persistedRepresentation),
            'ACL-005: persisted notification data must not contain an active raw download credential',
        );
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%document_download_links}} WHERE document_version_id = :version_id',
            [':version_id' => $result['versionId']],
        ));

        $deliveredBody = null;
        $processor = new NotificationOutboxProcessor(
            $this->db(),
            static function (int $request, string $email, string $name, string $subject, string $body) use (&$deliveredBody): void {
                if ($email === 'active.expert@example.invalid') {
                    $deliveredBody = $body;
                    throw new \RuntimeException('Synthetic SMTP failure');
                }
            },
        );
        $processor->processAvailableBatch(100);
        self::assertIsString($deliveredBody);
        preg_match('~/api/v1/document-links/([a-f0-9]{64})/download~', $deliveredBody, $deliveredLink);
        self::assertArrayHasKey(1, $deliveredLink);
        self::assertNotFalse($repository->findVersionByToken($deliveredLink[1]));
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%document_download_links}} WHERE token_hash = :token_hash',
            [':token_hash' => hash('sha256', $deliveredLink[1])],
        ));
        $linkCountAfterFailure = (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%document_download_links}} WHERE document_version_id = :version_id',
            [':version_id' => $result['versionId']],
        );
        $failedRepresentation = json_encode(
            $this->db()->createCommand(
                "SELECT * FROM {{%notification_outbox}} WHERE request_id = :id "
                . "AND recipient_email = 'active.expert@example.invalid'",
                [':id' => $requestId],
            )->queryOne(),
            JSON_THROW_ON_ERROR,
        );
        self::assertFalse($this->containsActiveDownloadCredential($failedRepresentation));

        $this->db()->createCommand()->update(
            '{{%notification_outbox}}',
            ['next_attempt_at' => Clock::now()],
            ['request_id' => $requestId, 'recipient_email' => 'active.expert@example.invalid'],
        )->execute();
        $retriedBody = null;
        (new NotificationOutboxProcessor(
            $this->db(),
            static function (int $request, string $email, string $name, string $subject, string $body) use (&$retriedBody): void {
                if ($email === 'active.expert@example.invalid') {
                    $retriedBody = $body;
                }
            },
        ))->processAvailableBatch(100);
        self::assertSame($deliveredBody, $retriedBody, 'SMTP ambiguity must preserve the same usable credential on retry.');
        self::assertSame($linkCountAfterFailure, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%document_download_links}} WHERE document_version_id = :version_id',
            [':version_id' => $result['versionId']],
        ));

        $legacyToken = bin2hex(random_bytes(32));
        $this->db()->createCommand()->insert('{{%document_download_links}}', [
            'document_version_id' => $result['versionId'],
            'token_hash' => hash('sha256', $legacyToken),
            'created_at' => Clock::now(),
        ])->execute();
        $this->db()->createCommand()->update(
            '{{%notification_outbox}}',
            [
                'status' => 'failed',
                'body' => 'Legacy body' . "\nСсылка на отчёт: http://legacy.invalid/api/v1/document-links/{$legacyToken}/download",
                'payload_json' => ['documentLinks' => []],
            ],
            ['request_id' => $requestId, 'recipient_email' => 'active.expert@example.invalid'],
        )->execute();
        (new NotificationOutboxCredentialCleanup($this->db()))->run();

        $remediatedRow = $this->db()->createCommand(
            "SELECT body, payload_json, status FROM {{%notification_outbox}} WHERE request_id = :id "
            . "AND recipient_email = 'active.expert@example.invalid'",
            [':id' => $requestId],
        )->queryOne();
        self::assertSame('failed', $remediatedRow['status']);
        self::assertStringNotContainsString($legacyToken, $remediatedRow['body']);
        $remediatedPayload = is_array($remediatedRow['payload_json'])
            ? $remediatedRow['payload_json']
            : json_decode($remediatedRow['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertContains(
            ['label' => 'отчёт', 'documentVersionId' => $result['versionId']],
            $remediatedPayload['documentLinks'],
        );
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%document_download_links}} WHERE token_hash = :token_hash',
            [':token_hash' => hash('sha256', $legacyToken)],
        ));
        self::assertNotFalse($repository->findVersionByToken($legacyToken));

        $auditRuleId = $this->scalar(
            "SELECT rule_id FROM {{%audit_events}} WHERE event_type = 'request.report_uploaded' "
            . 'AND entity_id = :id ORDER BY id DESC LIMIT 1',
            [':id' => $requestId],
        );
        self::assertSame('DOC-002', $auditRuleId);

        $history = (new RequestQuery($this->db()))->findDetails($requestId, $executor)['history'];
        $uploadEvent = array_values(array_filter(
            $history,
            static fn (array $event): bool => $event['action'] === 'upload_report',
        ));
        self::assertCount(1, $uploadEvent);
        self::assertSame((int) $result['versionId'], (int) $uploadEvent[0]['versionId']);
        self::assertSame('report.pdf', $uploadEvent[0]['originalName']);

        $outsiderHistory = (new RequestQuery($this->db()))->findDetails($requestId, $outsider)['history'];
        $outsiderUploadEvent = array_values(array_filter(
            $outsiderHistory,
            static fn (array $event): bool => $event['action'] === 'upload_report',
        ));
        self::assertNull($outsiderUploadEvent[0]['versionId']);
        self::assertNull($outsiderUploadEvent[0]['originalName']);
    }

    private function containsActiveDownloadCredential(string $persistedRepresentation): bool
    {
        preg_match_all(
            '~(?<![a-f0-9])([a-f0-9]{64})(?![a-f0-9])~',
            $persistedRepresentation,
            $matches,
        );
        foreach ($matches[1] as $token) {
            if (
                (int) $this->scalar(
                    'SELECT COUNT(*) FROM {{%document_download_links}} WHERE token_hash = :token_hash',
                    [':token_hash' => hash('sha256', $token)],
                ) > 0
            ) {
                return true;
            }
        }

        return false;
    }

    public function testDeletingReportFromCompletedRequestReturnsItToWorkAndWritesTransition(): void
    {
        $initiator = $this->createUser('dev.it.doc.delete-completed-initiator', 'Инициатор');
        $executor = $this->createUser('dev.it.doc.delete-completed-executor', 'Исполнитель');
        $request = $this->createInProgressRequestWithExecutor($initiator, $executor, 'delete-completed');
        $requestId = (int) $request['id'];
        $repository = new DocumentRepository($this->db(), $this->storage());
        $file = $this->tempPdf();
        $uploaded = $repository->uploadReport(
            $requestId,
            $executor,
            $file['name'],
            $file['mime'],
            $file['size'],
            $file['path'],
        );
        $this->db()->createCommand()->update('{{%requests}}', [
            'status' => 'completed',
        ], ['id' => $requestId])->execute();

        $deletion = $repository->deleteReport(
            $requestId,
            (int) $uploaded['lockVersion'],
            $executor,
            'Загружена неверная версия',
        );

        self::assertSame('in_progress', $deletion['status']);
        self::assertSame('in_progress', $this->scalar(
            'SELECT status FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        ));
        $deletionTransition = $this->db()->createCommand(
            "SELECT from_status, to_status, rule_id, reason FROM {{%request_transitions}} "
            . "WHERE request_id = :id AND action = 'delete_report'",
            [':id' => $requestId],
        )->queryOne();
        self::assertSame('completed', $deletionTransition['from_status']);
        self::assertSame('in_progress', $deletionTransition['to_status']);
        self::assertSame('DOC-011', $deletionTransition['rule_id']);
        self::assertSame('Загружена неверная версия', $deletionTransition['reason']);
        $historyAfterDeletion = (new RequestQuery($this->db()))->findDetails($requestId, $executor)['history'];
        $deletionEvent = array_values(array_filter(
            $historyAfterDeletion,
            static fn (array $event): bool => $event['action'] === 'delete_report',
        ));
        self::assertCount(1, $deletionEvent);
        self::assertSame('Загружена неверная версия', $deletionEvent[0]['reason']);
        $deletedUploadEvent = array_values(array_filter(
            $historyAfterDeletion,
            static fn (array $event): bool => $event['action'] === 'upload_report',
        ));
        self::assertCount(1, $deletedUploadEvent);
        self::assertNull($deletedUploadEvent[0]['versionId']);
        self::assertNull($deletedUploadEvent[0]['originalName']);
    }

    public function testUnrelatedEmployeeCannotUploadReport(): void
    {
        $initiator = $this->createUser('dev.it.doc.initiator2', 'Инициатор');
        $executor = $this->createUser('dev.it.doc.executor2', 'Исполнитель');
        $outsider = $this->createUser('dev.it.doc.outsider1', 'Посторонний сотрудник');
        $request = $this->createInProgressRequestWithExecutor($initiator, $executor, 'report-denied');
        $file = $this->tempPdf();

        $repository = new DocumentRepository($this->db(), $this->storage());
        $this->expectException(ReportDenied::class);
        $repository->uploadReport((int) $request['id'], $outsider, $file['name'], $file['mime'], $file['size'], $file['path']);
    }

    public function testManagerCanDeleteReportAndReturnRequestToWork(): void
    {
        $initiator = $this->createUser('dev.it.doc.delete-manager-initiator', 'Инициатор');
        $executor = $this->createUser('dev.it.doc.delete-manager-executor', 'Исполнитель');
        $manager = $this->createUser('dev.it.doc.delete-manager', 'Руководитель ИЦ');
        $this->grantRole($manager, 'ic_manager');
        $request = $this->createInProgressRequestWithExecutor($initiator, $executor, 'delete-by-manager');
        $requestId = (int) $request['id'];
        $repository = new DocumentRepository($this->db(), $this->storage());
        $file = $this->tempPdf();
        $uploaded = $repository->uploadReport(
            $requestId,
            $executor,
            $file['name'],
            $file['mime'],
            $file['size'],
            $file['path'],
        );

        $deleted = $repository->deleteReport($requestId, (int) $uploaded['lockVersion'], $manager, 'Исправление отчёта');

        self::assertSame('in_progress', $deleted['status']);
        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%request_documents}} WHERE request_id = :id "
            . "AND document_type = 'report' AND deleted_at IS NULL",
            [':id' => $requestId],
        ));
    }

    public function testUnrelatedEmployeeCannotDeleteReport(): void
    {
        $initiator = $this->createUser('dev.it.doc.delete-denied-initiator', 'Инициатор');
        $executor = $this->createUser('dev.it.doc.delete-denied-executor', 'Исполнитель');
        $outsider = $this->createUser('dev.it.doc.delete-denied-outsider', 'Посторонний сотрудник');
        $request = $this->createInProgressRequestWithExecutor($initiator, $executor, 'delete-denied');
        $requestId = (int) $request['id'];
        $repository = new DocumentRepository($this->db(), $this->storage());
        $file = $this->tempPdf();
        $uploaded = $repository->uploadReport(
            $requestId,
            $executor,
            $file['name'],
            $file['mime'],
            $file['size'],
            $file['path'],
        );

        try {
            $repository->deleteReport($requestId, (int) $uploaded['lockVersion'], $outsider, 'Нет полномочий');
            self::fail('Report deletion by an unrelated employee must be denied.');
        } catch (ReportDeletionDenied) {
            self::assertSame('opinion_preparation', $this->scalar(
                'SELECT status FROM {{%requests}} WHERE id = :id',
                [':id' => $requestId],
            ));
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%request_documents}} WHERE request_id = :id "
                . "AND document_type = 'report' AND deleted_at IS NULL",
                [':id' => $requestId],
            ));
        }
    }

    public function testStaleLockVersionDoesNotPartiallyDeleteReport(): void
    {
        $initiator = $this->createUser('dev.it.doc.delete-stale-initiator', 'Инициатор');
        $executor = $this->createUser('dev.it.doc.delete-stale-executor', 'Исполнитель');
        $request = $this->createInProgressRequestWithExecutor($initiator, $executor, 'delete-stale');
        $requestId = (int) $request['id'];
        $repository = new DocumentRepository($this->db(), $this->storage());
        $file = $this->tempPdf();
        $uploaded = $repository->uploadReport(
            $requestId,
            $executor,
            $file['name'],
            $file['mime'],
            $file['size'],
            $file['path'],
        );

        try {
            $repository->deleteReport($requestId, (int) $uploaded['lockVersion'] - 1, $executor, 'Устаревшая версия');
            self::fail('Report deletion with a stale lock version must be rejected.');
        } catch (ConcurrentRequestModification) {
            $requestAfterConflict = $this->db()->createCommand(
                'SELECT status, lock_version FROM {{%requests}} WHERE id = :id',
                [':id' => $requestId],
            )->queryOne();
            self::assertSame('opinion_preparation', $requestAfterConflict['status']);
            self::assertSame((int) $uploaded['lockVersion'], (int) $requestAfterConflict['lock_version']);
            self::assertSame(1, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%request_documents}} document "
                . "JOIN {{%request_document_versions}} version ON version.document_id = document.id "
                . "WHERE document.request_id = :id AND document.document_type = 'report' "
                . 'AND document.deleted_at IS NULL AND version.deleted_at IS NULL',
                [':id' => $requestId],
            ));
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_type = 'request' AND entity_id = :id "
                . "AND event_type = 'request.report_deleted'",
                [':id' => $requestId],
            ));
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%request_transitions}} WHERE request_id = :id "
                . "AND action = 'delete_report'",
                [':id' => $requestId],
            ));
        }
    }

    public function testSecondReportUploadCreatesNewVersionWithoutRepeatingStatusTransition(): void
    {
        $initiator = $this->createUser('dev.it.doc.initiator3', 'Инициатор');
        $executor = $this->createUser('dev.it.doc.executor3', 'Исполнитель');
        $request = $this->createInProgressRequestWithExecutor($initiator, $executor, 'report-revision');
        $requestId = (int) $request['id'];

        $repository = new DocumentRepository($this->db(), $this->storage());
        $first = $this->tempPdf('%PDF-1.4 revision one');
        $firstResult = $repository->uploadReport($requestId, $executor, $first['name'], $first['mime'], $first['size'], $first['path']);
        self::assertSame(1, $firstResult['version']);

        $second = $this->tempPdf('%PDF-1.4 revision two, slightly longer body');
        $secondResult = $repository->uploadReport($requestId, $executor, $second['name'], $second['mime'], $second['size'], $second['path']);
        self::assertSame(2, $secondResult['version']);
        self::assertSame($firstResult['lockVersion'], $secondResult['lockVersion']);

        $versionCount = $this->scalar(
            'SELECT COUNT(*) FROM {{%request_document_versions}} v '
            . "JOIN {{%request_documents}} d ON d.id = v.document_id "
            . "WHERE d.request_id = :id AND d.document_type = 'report'",
            [':id' => $requestId],
        );
        self::assertSame(2, (int) $versionCount);
    }

    public function testAssignedExpertCanDownloadTheReportButUnrelatedEmployeeCannot(): void
    {
        // Issue #73: эксперт, закреплённый за заявкой на подготовке
        // заключения, обязан видеть отчёт — иначе ему не по чему готовить
        // заключение. Посторонний сотрудник по-прежнему не должен иметь
        // доступа (DOC-003).
        $initiator = $this->createUser('dev.it.doc.initiator4', 'Инициатор');
        $executor = $this->createUser('dev.it.doc.executor4', 'Исполнитель');
        $expert = $this->createUser('dev.it.doc.expert3', 'Эксперт');
        $outsider = $this->createUser('dev.it.doc.outsider2', 'Посторонний сотрудник');
        $request = $this->createInProgressRequestWithExecutor($initiator, $executor, 'report-expert-visibility');
        $requestId = (int) $request['id'];

        $repository = new DocumentRepository($this->db(), $this->storage());
        $file = $this->tempPdf();
        $uploaded = $repository->uploadReport($requestId, $executor, $file['name'], $file['mime'], $file['size'], $file['path']);

        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'expert',
            'user_id' => $expert,
            'assigned_by' => $expert,
            'valid_from' => Clock::now(),
        ])->execute();

        $version = $repository->findVersionForDownload((int) $uploaded['versionId'], $expert);
        self::assertSame($requestId, $version['requestId']);

        $this->expectException(RequestNotFound::class);
        $repository->findVersionForDownload((int) $uploaded['versionId'], $outsider);
    }

    private function removeDirectory(string $path): void
    {
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- temporary test-only storage root
                unlink($itemPath);
            }
        }
        rmdir($path);
    }
}

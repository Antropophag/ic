<?php

declare(strict_types=1);

namespace Tests\Integration\Document;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\ReportDenied;
use App\Infrastructure\Document\DocumentRepository;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Request\RequestRepository;
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
        $request = (new RequestRepository($this->db()))->create($input, $initiatorId);
        $requestId = (int) $request['id'];
        $now = gmdate('Y-m-d H:i:s.u');

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

        $auditRuleId = $this->scalar(
            "SELECT rule_id FROM {{%audit_events}} WHERE event_type = 'request.report_uploaded' "
            . 'AND entity_id = :id ORDER BY id DESC LIMIT 1',
            [':id' => $requestId],
        );
        self::assertSame('DOC-002', $auditRuleId);
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

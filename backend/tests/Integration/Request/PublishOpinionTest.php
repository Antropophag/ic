<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\Command\PublishOpinionCommand;
use App\Application\Request\UseCase\PublishOpinion;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\OpinionDenied;
use App\Infrastructure\Clock;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Document\OpinionRendererAdapter;
use App\Infrastructure\Persistence\Request\PublishOpinionPersistenceAdapter;
use App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter;
use App\Http\Request\CreateRequest;
use Tests\Integration\IntegrationTestCase;

final class PublishOpinionTest extends IntegrationTestCase
{
    private ?string $storageRoot = null;

    protected function tearDown(): void
    {
        try {
            if ($this->storageRoot !== null) {
                $this->removeStorageTree($this->storageRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testPublicationPersistsPdfTransitionAuditAndDeduplicatedSecurityNotifications(): void
    {
        [$requestId, $expertId, $lockVersion] = $this->publicationFixture('success');
        $officer = $this->createUser('opinion-officer', 'Сотрудник СБ', 'security@example.invalid');
        $this->grantRole($officer, 'security_officer');
        $this->grantRole($officer, 'administrator');

        $result = $this->useCase()->execute(new PublishOpinionCommand(
            $requestId,
            $expertId,
            'Испытания завершены, требования выполнены.',
            $lockVersion,
        ));

        self::assertSame('security_review', $result->status->value);
        self::assertSame($lockVersion + 1, $result->lockVersion);
        $persistedRequest = $this->db()->createCommand(
            'SELECT status, lock_version FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryOne();
        self::assertSame('security_review', $persistedRequest['status']);
        self::assertSame($lockVersion + 1, (int) $persistedRequest['lock_version']);
        $version = $this->db()->createCommand(
            'SELECT original_name, mime_type, storage_key, size_bytes FROM {{%request_document_versions}} WHERE id = :id',
            [':id' => $result->documentVersionId],
        )->queryOne();
        self::assertMatchesRegularExpression('/^expert-opinion-\d{6}-v1\.pdf$/', (string) $version['original_name']);
        self::assertSame('application/pdf', $version['mime_type']);
        self::assertGreaterThan(100, (int) $version['size_bytes']);
        self::assertFileExists($this->storage()->path((string) $version['storage_key']));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.opinion_published' AND rule_id = 'DOC-007'",
            [':id' => $requestId],
        ));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id "
            . "AND event_type = 'request.opinion_published' AND recipient_email = 'security@example.invalid'",
            [':id' => $requestId],
        ));
    }

    public function testStalePublicationCreatesNoOpinionFileOrSuccessSideEffects(): void
    {
        [$requestId, $expertId, $lockVersion] = $this->publicationFixture('stale');

        try {
            $this->useCase()->execute(new PublishOpinionCommand($requestId, $expertId, str_repeat('Тест ', 3), $lockVersion - 1));
            self::fail('Expected stale publication conflict.');
        } catch (ConcurrentRequestModification) {
            self::assertSame([], $this->storedFiles());
            $persistedRequest = $this->db()->createCommand(
                'SELECT status, lock_version FROM {{%requests}} WHERE id = :id',
                [':id' => $requestId],
            )->queryOne();
            self::assertSame('opinion_preparation', $persistedRequest['status']);
            self::assertSame($lockVersion, (int) $persistedRequest['lock_version']);
            self::assertSame(0, (int) $this->scalar(
                'SELECT COUNT(*) FROM {{%expert_opinions}} WHERE request_id = :id',
                [':id' => $requestId],
            ));
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.opinion_published'",
                [':id' => $requestId],
            ));
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.opinion_published'",
                [':id' => $requestId],
            ));
        }
    }

    public function testUnassignedExpertCreatesNoOpinionOrSuccessAudit(): void
    {
        [$requestId, , $lockVersion] = $this->publicationFixture('denied');
        $outsider = $this->createUser('opinion-outsider', 'Другой эксперт');
        $this->grantRole($outsider, 'expert');

        try {
            $this->useCase()->execute(new PublishOpinionCommand($requestId, $outsider, str_repeat('Тест ', 3), $lockVersion));
            self::fail('Expected publication denial.');
        } catch (OpinionDenied $error) {
            self::assertSame('DOC-005', $error->ruleId);
            self::assertSame([], $this->storedFiles());
            $persistedRequest = $this->db()->createCommand(
                'SELECT status, lock_version FROM {{%requests}} WHERE id = :id',
                [':id' => $requestId],
            )->queryOne();
            self::assertSame('opinion_preparation', $persistedRequest['status']);
            self::assertSame($lockVersion, (int) $persistedRequest['lock_version']);
            self::assertSame(0, (int) $this->scalar(
                'SELECT COUNT(*) FROM {{%expert_opinions}} WHERE request_id = :id',
                [':id' => $requestId],
            ));
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.opinion_published'",
                [':id' => $requestId],
            ));
            self::assertSame(0, (int) $this->scalar(
                "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.opinion_published'",
                [':id' => $requestId],
            ));
        }
    }

    public function testAuditFailureRollsBackBusinessMutationOutboxAndStoredPdf(): void
    {
        [$requestId, $expertId, $lockVersion] = $this->publicationFixture('audit-failure');
        $originalCommandClass = $this->db()->commandClass;
        $this->db()->commandClass = ControlledPublishOpinionAuditFailureCommand::class;
        try {
            $this->useCase()->execute(new PublishOpinionCommand(
                $requestId,
                $expertId,
                'Заключение для проверки атомарного отката.',
                $lockVersion,
            ));
            self::fail('Expected controlled audit failure.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('controlled publish opinion audit failure', $error->getMessage());
        } finally {
            $this->db()->commandClass = $originalCommandClass;
        }

        self::assertSame([], $this->storedFiles());
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM {{%expert_opinions}} WHERE request_id = :id',
            [':id' => $requestId],
        ));
        $persistedRequest = $this->db()->createCommand(
            'SELECT status, lock_version FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryOne();
        self::assertSame('opinion_preparation', $persistedRequest['status']);
        self::assertSame($lockVersion, (int) $persistedRequest['lock_version']);
        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.opinion_published'",
            [':id' => $requestId],
        ));
        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} WHERE request_id = :id AND event_type = 'request.opinion_published'",
            [':id' => $requestId],
        ));
    }

    /** @return array{int, int, int} */
    private function publicationFixture(string $marker): array
    {
        $initiator = $this->createUser("opinion-initiator-{$marker}", 'Инициатор');
        $expert = $this->createUser("opinion-expert-{$marker}", 'Главный эксперт');
        $this->grantRole($expert, 'expert');
        $now = Clock::now();
        $input = new CreateRequest();
        $input->setAttributes([
            'productName' => "Лифт {$marker}", 'manufacturer' => 'Завод', 'supplier' => 'Поставщик',
            'sampleQuantity' => 1, 'testMethod' => 'Методика',
        ]);
        $created = (new \App\Application\Request\UseCase\CreateRequest(
            new RequestCreationPersistenceAdapter($this->db()),
        ))->execute($input->toCommand($initiator));
        $requestId = (int) $created->toArray()['id'];
        $this->db()->createCommand()->update('{{%requests}}', [
            'status' => 'opinion_preparation', 'lock_version' => 4, 'updated_at' => $now,
        ], ['id' => $requestId])->execute();
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId, 'assignment_type' => 'expert', 'user_id' => $expert,
            'assigned_by' => $initiator, 'valid_from' => $now,
        ])->execute();
        return [$requestId, $expert, 4];
    }

    private function useCase(): PublishOpinion
    {
        return new PublishOpinion(
            new PublishOpinionPersistenceAdapter($this->db(), $this->storage()),
            new OpinionRendererAdapter(),
        );
    }

    private function storage(): DocumentStorage
    {
        $this->storageRoot ??= sys_get_temp_dir() . '/ic-opinion-' . bin2hex(random_bytes(8));
        if (!is_dir($this->storageRoot)) {
            mkdir($this->storageRoot, 0700, true);
        }
        return new DocumentStorage($this->storageRoot);
    }

    /** @return list<string> */
    private function storedFiles(): array
    {
        if ($this->storageRoot === null || !is_dir($this->storageRoot)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageRoot)) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    private function removeStorageTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
}

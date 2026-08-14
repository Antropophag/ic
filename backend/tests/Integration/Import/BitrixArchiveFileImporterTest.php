<?php

declare(strict_types=1);

namespace Tests\Integration\Import;

use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Import\BitrixArchiveFileImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;

final class BitrixArchiveFileImporterTest extends IntegrationTestCase
{
    private string $workspace;
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(8));
        $this->workspace = sys_get_temp_dir() . '/ic-bitrix-files-' . $suffix;
        $this->storageRoot = sys_get_temp_dir() . '/ic-bitrix-storage-' . $suffix;
        mkdir($this->workspace . '/objects', 0700, true);
        mkdir($this->storageRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function testCommentAttachmentUsesHistoricalCommentTimestamp(): void
    {
        $userId = $this->createUser('dev.it.bitrix.file', 'Автор');
        $input = new CreateRequestInput();
        $input->productName = 'Архивное изделие';
        $input->manufacturer = 'Завод';
        $input->supplier = 'Поставщик';
        $input->sampleQuantity = 1;
        $input->testMethod = 'Методика';
        $request = (new \App\Application\Request\UseCase\CreateRequest(new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($this->db())))->execute($input->toCommand($userId))->toArray();
        $this->db()->createCommand()->update('{{%requests}}', [
            'legacy_id' => 'bitrix24:114:42',
            'source' => 'bitrix24',
            'is_archived' => 1,
            'created_at' => '2024-01-01 10:00:00',
        ], ['id' => $request['id']])->execute();
        $this->db()->createCommand()->insert('{{%request_comments}}', [
            'legacy_id' => 'bitrix24:114:42:comment:commentsInitiator:900',
            'request_id' => $request['id'],
            'author_id' => $userId,
            'body' => 'Исторический комментарий',
            'created_at' => '2024-02-03 12:34:56',
        ])->execute();

        $payload = 'legacy attachment';
        file_put_contents($this->workspace . '/objects/file_1', $payload);
        $association = [
            'requestNumber' => 42,
            'documentType' => 'comment',
            'commentType' => 'initiator',
            'sourceCommentId' => '900',
            'sourceFileId' => 'file_1',
            'originalName' => 'history.txt',
        ];
        file_put_contents($this->workspace . '/associations.jsonl', json_encode($association, JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($this->workspace . '/checkpoint.jsonl', json_encode([
            'sourceFileId' => 'file_1',
            'status' => 'downloaded',
            'bytes' => strlen($payload),
            'sha256' => hash('sha256', $payload),
            'mime' => 'text/plain',
        ], JSON_THROW_ON_ERROR) . "\n");

        $summary = (new BitrixArchiveFileImporter(
            $this->db(),
            new DocumentStorage($this->storageRoot),
        ))->import($this->workspace, true);

        self::assertSame(1, $summary['created']);
        $timestamps = $this->db()->createCommand(
            'SELECT d.created_at AS document_created_at, v.created_at AS version_created_at '
            . 'FROM {{%request_documents}} d JOIN {{%request_document_versions}} v ON v.document_id = d.id '
            . 'WHERE d.legacy_id = :legacy_id',
            [':legacy_id' => 'bitrix24:file:42:comment:900:file_1'],
        )->queryOne();
        self::assertSame('2024-02-03 12:34:56.000000', $timestamps['document_created_at'] ?? null);
        self::assertSame('2024-02-03 12:34:56.000000', $timestamps['version_created_at'] ?? null);
    }

    public function testRejectsDatabaseBoundViolationsBeforeImportingFiles(): void
    {
        $payload = 'legacy attachment';
        file_put_contents($this->workspace . '/objects/file_1', $payload);
        file_put_contents($this->workspace . '/checkpoint.jsonl', json_encode([
            'sourceFileId' => 'file_1',
            'status' => 'downloaded',
            'bytes' => strlen($payload),
            'sha256' => hash('sha256', $payload),
            'mime' => 'text/plain',
        ], JSON_THROW_ON_ERROR) . "\n");

        $cases = [
            ['sourceFileId' => str_repeat('a', 192), 'originalName' => 'history.txt', 'expected' => 'association legacy ID'],
            ['sourceFileId' => 'file_1', 'originalName' => str_repeat('Я', 256), 'expected' => 'original file name'],
        ];
        foreach ($cases as $case) {
            file_put_contents($this->workspace . '/associations.jsonl', json_encode([
                'requestNumber' => 42,
                'documentType' => 'supporting',
                'sourceFileId' => $case['sourceFileId'],
                'originalName' => $case['originalName'],
            ], JSON_THROW_ON_ERROR) . "\n");

            try {
                (new BitrixArchiveFileImporter(
                    $this->db(),
                    new DocumentStorage($this->storageRoot),
                ))->import($this->workspace, false);
                self::fail('Oversized import metadata was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString($case['expected'], $error->getMessage());
            }
        }
    }

    public function testAcceptsWorkspaceWithMatchingProvenance(): void
    {
        $this->writeWorkspaceSource(114, str_repeat('a', 64));
        file_put_contents($this->workspace . '/associations.jsonl', '');
        file_put_contents($this->workspace . '/checkpoint.jsonl', '');

        $summary = (new BitrixArchiveFileImporter(
            $this->db(),
            new DocumentStorage($this->storageRoot),
            114,
            str_repeat('a', 64),
        ))->import($this->workspace, false);

        self::assertSame(['records' => 0, 'created' => 0, 'skipped' => 0, 'unavailable' => 0, 'unmatched' => 0], $summary);
    }

    public function testRejectsWorkspaceWithWrongFingerprintBeforeReadingAssociations(): void
    {
        $this->writeWorkspaceSource(114, str_repeat('b', 64));

        $this->expectWorkspaceMismatch();
    }

    public function testRejectsWorkspaceWithWrongListIdBeforeReadingAssociations(): void
    {
        $this->writeWorkspaceSource(115, str_repeat('a', 64));

        $this->expectWorkspaceMismatch();
    }

    #[DataProvider('invalidFingerprintProvider')]
    public function testRejectsInvalidWorkspaceFingerprintBeforeReadingAssociations(mixed $fingerprint): void
    {
        $this->writeWorkspaceSource(114, $fingerprint);

        $this->expectWorkspaceMismatch();
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidFingerprintProvider(): iterable
    {
        yield 'wrong type' => [[]];
        yield 'integer' => [123];
        yield 'malformed string' => ['not-a-sha256'];
    }

    private function expectWorkspaceMismatch(): void
    {
        self::assertFileDoesNotExist($this->workspace . '/associations.jsonl');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workspace source does not match the verified snapshot.');

        (new BitrixArchiveFileImporter(
            $this->db(),
            new DocumentStorage($this->storageRoot),
            114,
            str_repeat('a', 64),
        ))->import($this->workspace, true);
    }

    private function writeWorkspaceSource(int $listId, mixed $fingerprint): void
    {
        file_put_contents($this->workspace . '/source.json', json_encode([
            'listId' => $listId,
            'snapshotFingerprint' => $fingerprint,
        ], JSON_THROW_ON_ERROR));
    }

    public function testRetryAfterUnavailableFileSkipsCompletedDocumentAndImportsRemainder(): void
    {
        $userId = $this->createUser('dev.it.bitrix.retry', 'Автор');
        $input = new CreateRequestInput();
        $input->productName = 'Архивное изделие';
        $input->manufacturer = 'Завод';
        $input->supplier = 'Поставщик';
        $input->sampleQuantity = 1;
        $input->testMethod = 'Методика';
        $request = (new \App\Application\Request\UseCase\CreateRequest(new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($this->db())))->execute($input->toCommand($userId))->toArray();
        $this->db()->createCommand()->update('{{%requests}}', [
            'legacy_id' => 'bitrix24:114:77', 'source' => 'bitrix24', 'is_archived' => 1,
        ], ['id' => $request['id']])->execute();

        $records = [];
        $checkpoints = [];
        foreach (['file_1', 'file_2'] as $index => $sourceFileId) {
            $payload = "legacy attachment {$index}";
            $records[] = [
                'requestNumber' => 77, 'documentType' => 'supporting',
                'sourceFileId' => $sourceFileId, 'originalName' => 'duplicate-title.txt',
            ];
            $checkpoints[] = [
                'sourceFileId' => $sourceFileId, 'status' => 'downloaded',
                'bytes' => strlen($payload), 'sha256' => hash('sha256', $payload), 'mime' => 'text/plain',
            ];
            if ($index === 0) {
                file_put_contents($this->workspace . '/objects/' . $sourceFileId, $payload);
            }
        }
        file_put_contents($this->workspace . '/associations.jsonl', implode("\n", array_map(
            static fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR),
            $records,
        )) . "\n");
        file_put_contents($this->workspace . '/checkpoint.jsonl', implode("\n", array_map(
            static fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR),
            $checkpoints,
        )) . "\n");
        $importer = new BitrixArchiveFileImporter($this->db(), new DocumentStorage($this->storageRoot));

        $firstAttempt = $importer->import($this->workspace, true);
        self::assertSame(
            ['records' => 2, 'created' => 1, 'skipped' => 0, 'unavailable' => 1, 'unmatched' => 0],
            $firstAttempt,
        );
        file_put_contents($this->workspace . '/objects/file_2', 'legacy attachment 1');
        $retry = $importer->import($this->workspace, true);

        self::assertSame(['records' => 2, 'created' => 1, 'skipped' => 1, 'unavailable' => 0, 'unmatched' => 0], $retry);
        self::assertSame([
            'bitrix24:file:77:supporting:-:file_1',
            'bitrix24:file:77:supporting:-:file_2',
        ], $this->db()->createCommand(
            'SELECT legacy_id FROM {{%request_documents}} WHERE request_id = :id AND title = :title ORDER BY legacy_id',
            [':id' => $request['id'], ':title' => 'duplicate-title.txt'],
        )->queryColumn());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}

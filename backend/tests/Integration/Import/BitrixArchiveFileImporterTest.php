<?php

declare(strict_types=1);

namespace Tests\Integration\Import;

use App\Application\Request\CreateRequestInput;
use App\Infrastructure\Document\DocumentStorage;
use App\Infrastructure\Import\BitrixArchiveFileImporter;
use App\Infrastructure\Request\RequestRepository;
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
        $request = (new RequestRepository($this->db()))->create($input, $userId);
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

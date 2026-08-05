<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Document;

use App\Infrastructure\Document\DocumentStorage;
use PHPUnit\Framework\TestCase;

final class DocumentStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ic-documents-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testStoresUnderOpaqueGeneratedKeyAndDeletesFile(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'ic-source-');
        self::assertIsString($source);
        file_put_contents($source, 'document');
        $storage = new DocumentStorage($this->root);

        $key = $storage->store($source);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
        self::assertSame('document', file_get_contents($storage->path($key)));
        $storage->delete($key);
        self::assertFileDoesNotExist($storage->path($key));
        unlink($source);
    }

    public function testRejectsPathTraversalInsteadOfResolvingIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DocumentStorage($this->root))->path('../../etc/passwd');
    }

    public function testFailedCopyLeavesNoPartialFile(): void
    {
        $storage = new DocumentStorage($this->root);
        try {
            $storage->store($this->root . '/missing-source');
            self::fail('Missing source must fail.');
        } catch (\RuntimeException) {
            self::assertSame([], $this->filesUnderRoot());
        }
    }

    public function testTrackedWriteIsDeletedWhenOuterTransactionRollsBack(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'ic-source-');
        self::assertIsString($source);
        file_put_contents($source, 'document');
        $storage = new DocumentStorage($this->root);
        $checkpoint = DocumentStorage::writeCheckpoint();

        $key = $storage->store($source);
        DocumentStorage::rollbackWritesSince($checkpoint);

        self::assertFileDoesNotExist($storage->path($key));
        unlink($source);
    }

    public function testTrackedWriteRemainsAfterOuterTransactionCommits(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'ic-source-');
        self::assertIsString($source);
        file_put_contents($source, 'document');
        $storage = new DocumentStorage($this->root);
        $checkpoint = DocumentStorage::writeCheckpoint();

        $key = $storage->store($source);
        DocumentStorage::discardWritesSince($checkpoint);

        self::assertFileExists($storage->path($key));
        unlink($source);
    }

    public function testInnerCommitKeepsWriteTrackedForOuterRollback(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'ic-source-');
        self::assertIsString($source);
        file_put_contents($source, 'document');
        $storage = new DocumentStorage($this->root);
        $outer = DocumentStorage::writeCheckpoint();
        $inner = DocumentStorage::writeCheckpoint();

        $key = $storage->store($source);
        DocumentStorage::discardWritesSince($inner);
        DocumentStorage::rollbackWritesSince($outer);

        self::assertFileDoesNotExist($storage->path($key));
        unlink($source);
    }

    public function testRejectsClosingScopesOutOfOrderWithoutLosingTracking(): void
    {
        $outer = DocumentStorage::writeCheckpoint();
        $inner = DocumentStorage::writeCheckpoint();

        try {
            DocumentStorage::discardWritesSince($outer);
            self::fail('Out-of-order scope close must fail.');
        } catch (\LogicException) {
            DocumentStorage::discardWritesSince($inner);
            DocumentStorage::discardWritesSince($outer);
        }

        $source = tempnam(sys_get_temp_dir(), 'ic-source-');
        self::assertIsString($source);
        file_put_contents($source, 'document');
        $storage = new DocumentStorage($this->root);
        $next = DocumentStorage::writeCheckpoint();
        $key = $storage->store($source);
        DocumentStorage::rollbackWritesSince($next);

        self::assertFileDoesNotExist($storage->path($key));
        unlink($source);
    }

    public function testWritableProbeCleansUpAfterItself(): void
    {
        mkdir($this->root, 0700, true);
        $storage = new DocumentStorage($this->root);

        $storage->assertWritable();

        self::assertSame([], $this->filesUnderRoot());
    }

    /** @return list<string> */
    private function filesUnderRoot(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }
        return $files;
    }
}

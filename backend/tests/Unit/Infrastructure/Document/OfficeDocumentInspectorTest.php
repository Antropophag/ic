<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Document;

use App\Domain\Request\AttachmentDenied;
use App\Infrastructure\Document\OfficeDocumentInspector;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class OfficeDocumentInspectorTest extends TestCase
{
    public function testAcceptsDocxStructureAndNormalizesGenericZipMime(): void
    {
        $path = $this->createArchive(['[Content_Types].xml', '_rels/.rels', 'word/document.xml']);
        try {
            self::assertSame(
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                (new OfficeDocumentInspector())->normalizeMimeType('protocol.docx', 'application/zip', $path),
            );
        } finally {
            unlink($path);
        }
    }

    public function testRejectsArbitraryZipRenamedAsOfficeDocument(): void
    {
        $path = $this->createArchive(['notes.txt']);
        try {
            $this->expectException(AttachmentDenied::class);
            (new OfficeDocumentInspector())->normalizeMimeType('protocol.docx', 'application/zip', $path);
        } finally {
            unlink($path);
        }
    }

    public function testLeavesNonOfficeMimeUnchanged(): void
    {
        self::assertSame(
            'application/pdf',
            (new OfficeDocumentInspector())->normalizeMimeType('protocol.pdf', 'application/pdf', '/missing'),
        );
    }

    /** @param list<string> $entries */
    private function createArchive(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'office-test-');
        self::assertNotFalse($path);
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $entry) {
            $archive->addFromString($entry, '<xml/>');
        }
        $archive->close();
        return $path;
    }
}

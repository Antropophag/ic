<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

use App\Domain\Request\AttachmentDenied;
use ZipArchive;

final class OfficeDocumentInspector
{
    private const MAX_ENTRIES = 10_000;
    private const MAX_UNCOMPRESSED_SIZE = 50 * 1024 * 1024;

    public function normalizeMimeType(string $name, string $detectedMimeType, string $path): string
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $expectedPart = match ($extension) {
            'docx' => 'word/document.xml',
            'xlsx' => 'xl/workbook.xml',
            default => null,
        };
        if ($expectedPart === null || $detectedMimeType !== 'application/zip') {
            return $detectedMimeType;
        }

        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            throw new AttachmentDenied('COM-007');
        }
        try {
            if ($archive->numFiles > self::MAX_ENTRIES) {
                throw new AttachmentDenied('COM-007');
            }
            $uncompressedSize = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                if ($stat === false) {
                    throw new AttachmentDenied('COM-007');
                }
                $uncompressedSize += (int) $stat['size'];
                if ($uncompressedSize > self::MAX_UNCOMPRESSED_SIZE) {
                    throw new AttachmentDenied('COM-007');
                }
            }
            foreach (['[Content_Types].xml', '_rels/.rels', $expectedPart] as $requiredPart) {
                if ($archive->locateName($requiredPart, ZipArchive::FL_NOCASE) === false) {
                    throw new AttachmentDenied('COM-007');
                }
            }
        } finally {
            $archive->close();
        }

        return match ($extension) {
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}

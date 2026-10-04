<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\RequestStatus;

final readonly class UploadReportResult
{
    public function __construct(
        public int $documentId,
        public int $versionId,
        public int $version,
        public string $originalName,
        public string $mimeType,
        public int $size,
        public string $sha256,
        public int $uploadedBy,
        public string $createdAt,
        public RequestStatus $status,
        public int $lockVersion,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->documentId,
            'documentType' => 'report',
            'title' => 'Отчёт испытаний',
            'versionId' => $this->versionId,
            'version' => $this->version,
            'originalName' => $this->originalName,
            'mimeType' => $this->mimeType,
            'sizeBytes' => $this->size,
            'sha256' => $this->sha256,
            'uploadedBy' => $this->uploadedBy,
            'createdAt' => str_replace(' ', 'T', $this->createdAt) . 'Z',
            'status' => $this->status->value,
            'lockVersion' => $this->lockVersion,
        ];
    }
}

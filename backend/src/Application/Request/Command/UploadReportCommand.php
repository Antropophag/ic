<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

final readonly class UploadReportCommand
{
    public function __construct(
        public int $requestId,
        public int $actorId,
        public string $originalName,
        public string $mimeType,
        public int $size,
        public string $temporaryPath,
    ) {
    }
}

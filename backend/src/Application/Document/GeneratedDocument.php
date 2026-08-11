<?php

declare(strict_types=1);

namespace App\Application\Document;

final readonly class GeneratedDocument
{
    public function __construct(
        public string $fileName,
        public string $mimeType,
        public string $content,
    ) {
    }
}

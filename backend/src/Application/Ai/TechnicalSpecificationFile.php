<?php

declare(strict_types=1);

namespace App\Application\Ai;

final readonly class TechnicalSpecificationFile
{
    public function __construct(
        public string $name,
        public string $mimeType,
        public string $path,
        public int $size,
    ) {
    }
}

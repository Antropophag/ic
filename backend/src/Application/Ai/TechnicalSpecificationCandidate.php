<?php

declare(strict_types=1);

namespace App\Application\Ai;

final readonly class TechnicalSpecificationCandidate
{
    public function __construct(
        public int $versionId,
        public string $name,
        public string $mimeType,
        public int $version,
    ) {
    }

    /** @return array{versionId: int, name: string, mimeType: string, version: int} */
    public function toArray(): array
    {
        return ['versionId' => $this->versionId, 'name' => $this->name, 'mimeType' => $this->mimeType, 'version' => $this->version];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ai;

use App\Application\Ai\TechnicalSpecificationCandidate;
use App\Application\Ai\TechnicalSpecificationDocumentPort;
use App\Application\Ai\TechnicalSpecificationFile;

final class FakeTechnicalSpecificationDocuments implements TechnicalSpecificationDocumentPort
{
    public ?int $readVersionId = null;
    public string $path = '/storage/tz.docx';
    public int $size = 14;

    /** @param list<TechnicalSpecificationCandidate> $items */
    public function __construct(private readonly array $items)
    {
    }

    public function candidates(int $requestId, int $actorId): array
    {
        return $this->items;
    }

    public function file(int $requestId, int $versionId, int $actorId): TechnicalSpecificationFile
    {
        $this->readVersionId = $versionId;
        return new TechnicalSpecificationFile('тз.docx', 'application/vnd.test', $this->path, $this->size);
    }
}

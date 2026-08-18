<?php

declare(strict_types=1);

namespace App\Application\Ai;

interface TechnicalSpecificationDocumentPort
{
    /** @return list<TechnicalSpecificationCandidate> */
    public function candidates(int $requestId, int $actorId): array;

    public function file(int $requestId, int $versionId, int $actorId): TechnicalSpecificationFile;
}

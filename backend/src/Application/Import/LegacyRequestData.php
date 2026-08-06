<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Request\RequestStatus;
use DateTimeImmutable;

final readonly class LegacyRequestData
{
    public function __construct(
        public string $legacyId,
        public int $number,
        public string $productName,
        public string $manufacturer,
        public string $supplier,
        public int $sampleQuantity,
        public string $testMethod,
        public RequestStatus $status,
        public DateTimeImmutable $createdAt,
        public LegacyUserData $creator,
        public string $department,
        public int $supportingDocumentCount,
        public int $reportCount,
        public ?string $departmentExternalId = null,
    ) {
    }
}

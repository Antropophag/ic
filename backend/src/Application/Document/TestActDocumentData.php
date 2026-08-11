<?php

declare(strict_types=1);

namespace App\Application\Document;

final readonly class TestActDocumentData
{
    public function __construct(
        public int $requestNumber,
        public string $actNumber,
        public string $actDate,
        public string $sampleName,
        public string $basis,
        public string $result,
        public string $testCenterManagerName,
        public string $contactEmail,
        public string $executorName,
        public string $executorPosition,
    ) {
    }
}

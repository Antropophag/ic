<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

final readonly class CreateRequestCommand
{
    public function __construct(
        public int $initiatorId,
        public string $productName,
        public string $manufacturer,
        public string $supplier,
        public int $sampleQuantity,
        public string $testMethod,
    ) {
    }
}

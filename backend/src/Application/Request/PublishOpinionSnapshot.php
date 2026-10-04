<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\RequestStatus;

final readonly class PublishOpinionSnapshot
{
    public function __construct(
        public int $number,
        public RequestStatus $status,
        public int $lockVersion,
        public string $productName,
        public string $manufacturer,
        public string $supplier,
        public string $expertName,
        public string $expertPosition,
        public bool $actorIsActive,
        public bool $isCurrentExpert,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\RequestStatus;

final readonly class RequestCancellationSnapshot
{
    public function __construct(
        public RequestStatus $status,
        public int $lockVersion,
        public int $initiatorId,
        public bool $reviewedBySecurity,
    ) {
    }
}

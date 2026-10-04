<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\RequestStatus;

final readonly class SecurityDecisionResult
{
    public function __construct(
        public int $requestId,
        public string $decision,
        public RequestStatus $status,
        public int $lockVersion,
    ) {
    }

    /** @return array{requestId: int, decision: string, status: string, lockVersion: int} */
    public function toArray(): array
    {
        return [
            'requestId' => $this->requestId,
            'decision' => $this->decision,
            'status' => $this->status->value,
            'lockVersion' => $this->lockVersion,
        ];
    }
}

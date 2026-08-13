<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestStatus;

final readonly class RequestLifecycleResult
{
    public function __construct(
        public int $requestId,
        public RequestStatus $status,
        public int $lockVersion,
        public RequestAction $action,
        public string $changedAt,
    ) {
    }

    /** @return array{requestId: int, status: string, lockVersion: int}|array{requestId: int, status: string, lockVersion: int, startedAt: string} */
    public function toArray(): array
    {
        $result = [
            'requestId' => $this->requestId,
            'status' => $this->status->value,
            'lockVersion' => $this->lockVersion,
        ];

        if ($this->action === RequestAction::Start) {
            $result['startedAt'] = $this->changedAt;
        }

        return $result;
    }
}

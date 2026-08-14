<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\RequestStatus;

final readonly class DeleteReportResult
{
    public function __construct(public RequestStatus $status, public int $lockVersion)
    {
    }

    /** @return array{status: string, lockVersion: int} */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'lockVersion' => $this->lockVersion];
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\RequestStatus;

final readonly class PublishOpinionResult
{
    public function __construct(
        public int $requestId,
        public int $revision,
        public int $documentVersionId,
        public RequestStatus $status,
        public int $lockVersion,
    ) {
    }

    /** @return array{requestId: int, revision: int, documentVersionId: int, status: string, lockVersion: int} */
    public function toArray(): array
    {
        return [
            'requestId' => $this->requestId,
            'revision' => $this->revision,
            'documentVersionId' => $this->documentVersionId,
            'status' => $this->status->value,
            'lockVersion' => $this->lockVersion,
        ];
    }
}

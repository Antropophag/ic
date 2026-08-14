<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\DeleteReportCommand;
use App\Application\Request\Command\UploadReportCommand;
use App\Application\Request\DeleteReportResult;
use App\Application\Request\Port\ReportLifecycleGateway;
use App\Application\Request\ReportLifecycleSnapshot;
use App\Application\Request\UploadReportResult;
use App\Domain\Request\RequestStatus;

final class InMemoryReportLifecycleGateway implements ReportLifecycleGateway
{
    public int $transactionCount = 0;
    public int $hashCount = 0;
    public int $activeReportLookupCount = 0;
    public ?UploadReportCommand $uploaded = null;
    public ?DeleteReportCommand $deleted = null;

    public function __construct(
        public ?ReportLifecycleSnapshot $snapshot,
        public ?int $activeReportId = 51,
    ) {
    }

    public function transactional(callable $operation): mixed
    {
        ++$this->transactionCount;
        return $operation();
    }

    public function hashUploadedFile(string $temporaryPath): string
    {
        ++$this->hashCount;
        return hash('sha256', 'report');
    }

    public function snapshotForUpdate(int $requestId, int $actorId): ?ReportLifecycleSnapshot
    {
        return $this->snapshot;
    }

    public function persistUpload(
        UploadReportCommand $command,
        ReportLifecycleSnapshot $snapshot,
        string $sha256,
    ): UploadReportResult {
        $this->uploaded = $command;
        return new UploadReportResult(
            51,
            61,
            1,
            $command->originalName,
            $command->mimeType,
            $command->size,
            $sha256,
            $command->actorId,
            '2026-08-14 10:00:00.000000',
            RequestStatus::OpinionPreparation,
            $snapshot->lockVersion + 1,
        );
    }

    public function activeReportIdForUpdate(int $requestId): ?int
    {
        ++$this->activeReportLookupCount;
        return $this->activeReportId;
    }

    public function persistDeletion(
        DeleteReportCommand $command,
        ReportLifecycleSnapshot $snapshot,
        int $documentId,
    ): DeleteReportResult {
        $this->deleted = $command;
        return new DeleteReportResult(RequestStatus::InProgress, $command->expectedLockVersion + 1);
    }

    public function recordRejectedUpload(int $requestId, int $actorId, string $ruleId): void
    {
    }

    public function recordRejectedDeletion(int $requestId, int $actorId, string $ruleId): void
    {
    }
}

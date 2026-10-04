<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\Command\DeleteReportCommand;
use App\Application\Request\Command\UploadReportCommand;
use App\Application\Request\DeleteReportResult;
use App\Application\Request\Port\ReportLifecycleGateway;
use App\Application\Request\UploadReportResult;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\ReportDeletionPolicy;
use App\Domain\Request\ReportPolicy;
use App\Domain\Request\RequestNotFound;

final readonly class ReportLifecycle
{
    public function __construct(
        private ReportLifecycleGateway $gateway,
        private ReportPolicy $uploadPolicy = new ReportPolicy(),
        private ReportDeletionPolicy $deletionPolicy = new ReportDeletionPolicy(),
    ) {
    }

    public function upload(UploadReportCommand $command): UploadReportResult
    {
        $this->uploadPolicy->assertValidFile($command->originalName, $command->mimeType, $command->size);
        $sha256 = $this->gateway->hashUploadedFile($command->temporaryPath);

        return $this->gateway->transactional(function () use ($command, $sha256): UploadReportResult {
            $snapshot = $this->gateway->snapshotForUpdate($command->requestId, $command->actorId);
            if ($snapshot === null) {
                throw new RequestNotFound('Request not found');
            }
            $this->uploadPolicy->assertCanUpload(
                $snapshot->status,
                $snapshot->isExecutor,
                $snapshot->isManager,
                $snapshot->hasActiveReport,
            );

            return $this->gateway->persistUpload($command, $snapshot, $sha256);
        });
    }

    public function delete(DeleteReportCommand $command): DeleteReportResult
    {
        return $this->gateway->transactional(function () use ($command): DeleteReportResult {
            $snapshot = $this->gateway->snapshotForUpdate($command->requestId, $command->actorId);
            if ($snapshot === null) {
                throw new RequestNotFound('Request not found');
            }
            $documentId = $this->gateway->activeReportIdForUpdate($command->requestId);
            $this->deletionPolicy->assertCanDelete(
                $snapshot->isExecutor,
                $snapshot->isManager,
                $documentId !== null,
            );
            if ($snapshot->lockVersion !== $command->expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }

            return $this->gateway->persistDeletion($command, $snapshot, $documentId);
        });
    }

    public function recordRejectedUpload(int $requestId, int $actorId, string $ruleId): void
    {
        $this->gateway->recordRejectedUpload($requestId, $actorId, $ruleId);
    }

    public function recordRejectedDeletion(int $requestId, int $actorId, string $ruleId): void
    {
        $this->gateway->recordRejectedDeletion($requestId, $actorId, $ruleId);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\Command\DeleteReportCommand;
use App\Application\Request\Command\UploadReportCommand;
use App\Application\Request\DeleteReportResult;
use App\Application\Request\ReportLifecycleSnapshot;
use App\Application\Request\UploadReportResult;

interface ReportLifecycleGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function hashUploadedFile(string $temporaryPath): string;

    public function snapshotForUpdate(int $requestId, int $actorId): ?ReportLifecycleSnapshot;

    public function persistUpload(
        UploadReportCommand $command,
        ReportLifecycleSnapshot $snapshot,
        string $sha256,
    ): UploadReportResult;

    public function activeReportIdForUpdate(int $requestId): ?int;

    public function persistDeletion(
        DeleteReportCommand $command,
        ReportLifecycleSnapshot $snapshot,
        int $documentId,
    ): DeleteReportResult;

    public function recordRejectedUpload(int $requestId, int $actorId, string $ruleId): void;

    public function recordRejectedDeletion(int $requestId, int $actorId, string $ruleId): void;
}

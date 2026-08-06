<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class ReportDeletionPolicy
{
    public function assertCanDelete(bool $isExecutor, bool $isManager, bool $hasActiveReport): void
    {
        if (!$isExecutor && !$isManager) {
            throw new ReportDeletionDenied();
        }
        if (!$hasActiveReport) {
            throw new ReportDeletionDenied();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class ReportPolicy
{
    public function assertCanUpload(RequestStatus $status, bool $isExecutor, bool $isManager, bool $hasActiveReport): void
    {
        // ТЗ 7.8: после удаления отчёта его можно загрузить заново, даже
        // если заявка уже выполнена — цикл ГК → СБ запускается повторно
        // (см. ReportLifecycle::upload()).
        $statusAllowed = in_array($status, [RequestStatus::InProgress, RequestStatus::OpinionPreparation], true)
            || ($status === RequestStatus::Completed && !$hasActiveReport);
        if (!$statusAllowed || (!$isExecutor && !$isManager)) {
            throw new ReportDenied();
        }
    }

    public function assertValidFile(string $name, string $mimeType, int $size): void
    {
        try {
            (new AttachmentPolicy())->assertValidFile($name, $mimeType, $size);
        } catch (AttachmentDenied) {
            throw new ReportDenied('DOC-002A');
        }
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new ReportDenied('DOC-002A');
        }
    }
}

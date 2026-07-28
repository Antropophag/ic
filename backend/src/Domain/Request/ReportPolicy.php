<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class ReportPolicy
{
    public function assertCanUpload(RequestStatus $status, bool $isExecutor, bool $isManager): void
    {
        if (
            !in_array($status, [RequestStatus::InProgress, RequestStatus::OpinionPreparation], true)
            || (!$isExecutor && !$isManager)
        ) {
            throw new ReportDenied();
        }
    }

    public function assertValidFile(string $name, string $mimeType, int $size): void
    {
        try {
            (new AttachmentPolicy())->assertValidFile($name, $mimeType, $size);
        } catch (AttachmentDenied) {
            throw new ReportDenied('DOC-008');
        }
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new ReportDenied('DOC-008');
        }
    }
}

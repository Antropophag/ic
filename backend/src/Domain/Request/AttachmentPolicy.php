<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class AttachmentPolicy
{
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    /** @var array<string, list<string>> */
    private const ALLOWED_TYPES = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    public function assertCanUpload(RequestStatus $status): void
    {
        if (
            !in_array($status, [
            RequestStatus::Registered,
            RequestStatus::InProgress,
            RequestStatus::Suspended,
            RequestStatus::OpinionPreparation,
            RequestStatus::SecurityReview,
            ], true)
        ) {
            throw new AttachmentDenied();
        }
    }

    public function assertValidFile(string $name, string $mimeType, int $size): void
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (
            $name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name) === 1
            || $size < 1 || $size > self::MAX_SIZE_BYTES
            || !isset(self::ALLOWED_TYPES[$extension])
            || !in_array($mimeType, self::ALLOWED_TYPES[$extension], true)
        ) {
            throw new AttachmentDenied('COM-007');
        }
    }
}

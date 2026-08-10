<?php

declare(strict_types=1);

namespace App\Application\Import;

use DateTimeImmutable;

final readonly class LegacyCommentData
{
    public function __construct(
        public string $legacyId,
        public string $body,
        public DateTimeImmutable $createdAt,
        public LegacyUserData $creator,
        public int $fileCount,
    ) {
    }
}

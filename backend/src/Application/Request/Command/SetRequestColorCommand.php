<?php

declare(strict_types=1);

namespace App\Application\Request\Command;

use App\Domain\Request\RequestColor;

final readonly class SetRequestColorCommand
{
    public function __construct(
        public int $requestId,
        public RequestColor $color,
        public int $expectedLockVersion,
        public int $actorId,
    ) {
    }
}

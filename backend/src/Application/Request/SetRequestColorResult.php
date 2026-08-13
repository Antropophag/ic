<?php

declare(strict_types=1);

namespace App\Application\Request;

use App\Domain\Request\RequestColor;

final readonly class SetRequestColorResult
{
    public function __construct(
        public int $requestId,
        public RequestColor $color,
        public int $lockVersion,
    ) {
    }

    /** @return array{requestId: int, color: string, lockVersion: int} */
    public function toArray(): array
    {
        return ['requestId' => $this->requestId, 'color' => $this->color->value, 'lockVersion' => $this->lockVersion];
    }
}

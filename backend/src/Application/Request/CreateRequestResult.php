<?php

declare(strict_types=1);

namespace App\Application\Request;

final readonly class CreateRequestResult
{
    /** @param array<string, mixed> $request */
    public function __construct(private array $request)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->request;
    }
}

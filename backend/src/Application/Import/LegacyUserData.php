<?php

declare(strict_types=1);

namespace App\Application\Import;

final readonly class LegacyUserData
{
    public function __construct(
        public string $bitrixId,
        public string $adLogin,
        public string $displayName,
        public string $email,
        public ?string $position,
        public bool $active,
    ) {
    }
}

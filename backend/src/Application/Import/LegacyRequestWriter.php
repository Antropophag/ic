<?php

declare(strict_types=1);

namespace App\Application\Import;

interface LegacyRequestWriter
{
    public function write(LegacyRequestData $request): LegacyImportOutcome;
}

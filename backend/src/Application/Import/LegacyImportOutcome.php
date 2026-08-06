<?php

declare(strict_types=1);

namespace App\Application\Import;

enum LegacyImportOutcome
{
    case Created;
    case Skipped;
}

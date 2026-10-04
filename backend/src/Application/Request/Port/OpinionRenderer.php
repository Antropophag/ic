<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\PublishOpinionSnapshot;

interface OpinionRenderer
{
    public function render(PublishOpinionSnapshot $snapshot, string $body): string;
}

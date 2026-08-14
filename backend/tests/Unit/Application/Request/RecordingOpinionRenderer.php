<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Port\OpinionRenderer;
use App\Application\Request\PublishOpinionSnapshot;

final class RecordingOpinionRenderer implements OpinionRenderer
{
    public ?string $body = null;

    public function __construct(private readonly string $pdf)
    {
    }

    public function render(PublishOpinionSnapshot $snapshot, string $body): string
    {
        $this->body = $body;
        return $this->pdf;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

use App\Application\Request\Port\OpinionRenderer;
use App\Application\Request\PublishOpinionSnapshot;

final readonly class OpinionRendererAdapter implements OpinionRenderer
{
    public function __construct(private OpinionPdfRenderer $renderer = new OpinionPdfRenderer())
    {
    }

    public function render(PublishOpinionSnapshot $snapshot, string $body): string
    {
        return $this->renderer->render([
            'number' => $snapshot->number,
            'productName' => $snapshot->productName,
            'manufacturer' => $snapshot->manufacturer,
            'supplier' => $snapshot->supplier,
            'expertName' => $snapshot->expertName,
            'expertPosition' => $snapshot->expertPosition,
            'body' => $body,
            'date' => gmdate('d.m.Y'),
        ]);
    }
}

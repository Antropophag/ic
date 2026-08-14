<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\PublishOpinionCommand;
use App\Application\Request\Port\PublishOpinionGateway;
use App\Application\Request\PublishOpinionSnapshot;

final class InMemoryPublishOpinionGateway implements PublishOpinionGateway
{
    public int $transactionCount = 0;
    public ?string $persistedPdf = null;

    public function __construct(private readonly ?PublishOpinionSnapshot $snapshot)
    {
    }

    public function transactional(callable $operation): mixed
    {
        ++$this->transactionCount;
        return $operation();
    }

    public function snapshotForUpdate(int $requestId, int $actorId): ?PublishOpinionSnapshot
    {
        return $this->snapshot;
    }

    public function nextRevision(int $requestId): int
    {
        return 2;
    }

    public function persistPublication(
        PublishOpinionCommand $command,
        PublishOpinionSnapshot $snapshot,
        int $revision,
        string $pdf,
    ): int {
        $this->persistedPdf = $pdf;
        return 73;
    }

    public function recordRejected(int $requestId, int $actorId, string $ruleId): void
    {
    }
}

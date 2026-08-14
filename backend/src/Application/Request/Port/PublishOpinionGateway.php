<?php

declare(strict_types=1);

namespace App\Application\Request\Port;

use App\Application\Request\Command\PublishOpinionCommand;
use App\Application\Request\PublishOpinionSnapshot;

interface PublishOpinionGateway
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function snapshotForUpdate(int $requestId, int $actorId): ?PublishOpinionSnapshot;

    public function nextRevision(int $requestId): int;

    public function persistPublication(
        PublishOpinionCommand $command,
        PublishOpinionSnapshot $snapshot,
        int $revision,
        string $pdf,
    ): int;

    public function recordRejected(int $requestId, int $actorId, string $ruleId): void;
}

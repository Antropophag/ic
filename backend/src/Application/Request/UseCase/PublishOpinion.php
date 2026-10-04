<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\Command\PublishOpinionCommand;
use App\Application\Request\Port\OpinionRenderer;
use App\Application\Request\Port\PublishOpinionGateway;
use App\Application\Request\PublishOpinionResult;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\OpinionPolicy;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;

final readonly class PublishOpinion
{
    public function __construct(
        private PublishOpinionGateway $gateway,
        private OpinionRenderer $renderer,
        private OpinionPolicy $policy = new OpinionPolicy(),
    ) {
    }

    public function execute(PublishOpinionCommand $command): PublishOpinionResult
    {
        return $this->gateway->transactional(function () use ($command): PublishOpinionResult {
            $snapshot = $this->gateway->snapshotForUpdate($command->requestId, $command->actorId);
            if ($snapshot === null) {
                throw new RequestNotFound('Request not found');
            }
            $this->policy->assertCanPublish(
                $snapshot->status,
                $snapshot->actorIsActive,
                $snapshot->isCurrentExpert,
            );
            if ($snapshot->lockVersion !== $command->expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }

            $revision = $this->gateway->nextRevision($command->requestId);
            $pdf = $this->renderer->render($snapshot, $command->body);
            $versionId = $this->gateway->persistPublication($command, $snapshot, $revision, $pdf);
            $nextLockVersion = $command->expectedLockVersion + 1;

            return new PublishOpinionResult(
                $command->requestId,
                $revision,
                $versionId,
                RequestStatus::SecurityReview,
                $nextLockVersion,
            );
        });
    }

    public function recordRejected(PublishOpinionCommand $command, string $ruleId): void
    {
        $this->gateway->recordRejected($command->requestId, $command->actorId, $ruleId);
    }
}

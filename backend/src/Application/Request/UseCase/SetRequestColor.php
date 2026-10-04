<?php

declare(strict_types=1);

namespace App\Application\Request\UseCase;

use App\Application\Request\Command\SetRequestColorCommand;
use App\Application\Request\Port\RequestColorGateway;
use App\Application\Request\SetRequestColorResult;
use App\Domain\Request\ColorMarkPolicy;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestNotFound;

final readonly class SetRequestColor
{
    private const RULE_ID = 'WF-009';

    public function __construct(
        private RequestColorGateway $gateway,
        private ColorMarkPolicy $policy = new ColorMarkPolicy(),
    ) {
    }

    public function execute(SetRequestColorCommand $command): SetRequestColorResult
    {
        return $this->gateway->transactional(function () use ($command): SetRequestColorResult {
            $lockVersion = $this->gateway->lockVersionForUpdate($command->requestId);
            if ($lockVersion === null) {
                throw new RequestNotFound('Request not found');
            }
            if ($lockVersion !== $command->expectedLockVersion) {
                throw new ConcurrentRequestModification();
            }

            $this->policy->assertCanSetColor(
                $this->gateway->rolesFor($command->actorId),
                $this->gateway->isActiveUser($command->actorId),
            );

            $nextLockVersion = $lockVersion + 1;
            $this->gateway->persistColorChange($command->requestId, $command->color, $nextLockVersion);
            $this->gateway->recordColorMarked(
                $command->requestId,
                $command->actorId,
                $command->color,
                self::RULE_ID,
            );

            return new SetRequestColorResult($command->requestId, $command->color, $nextLockVersion);
        });
    }

    public function recordRejected(int $requestId, int $actorId, string $ruleId): void
    {
        $this->gateway->recordRejectedColor($requestId, $actorId, $ruleId);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Request;

final class WithdrawPolicy
{
    public function assertCanWithdraw(bool $isInitiator, bool $isActorActive): void
    {
        if (!$isActorActive) {
            throw new WithdrawDenied('AUTH-003');
        }

        if (!$isInitiator) {
            throw new WithdrawDenied('WF-007');
        }
    }
}

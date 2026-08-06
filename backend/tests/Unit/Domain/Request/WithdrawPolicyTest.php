<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\WithdrawDenied;
use App\Domain\Request\WithdrawPolicy;
use PHPUnit\Framework\TestCase;

final class WithdrawPolicyTest extends TestCase
{
    public function testInitiatorCanWithdraw(): void
    {
        (new WithdrawPolicy())->assertCanWithdraw(true, true);
        self::addToAssertionCount(1);
    }

    public function testNonInitiatorCannotWithdraw(): void
    {
        $this->expectDenied('WF-007', fn () => (new WithdrawPolicy())->assertCanWithdraw(false, true));
    }

    public function testDisabledInitiatorCannotWithdraw(): void
    {
        $this->expectDenied('AUTH-003', fn () => (new WithdrawPolicy())->assertCanWithdraw(true, false));
    }

    private function expectDenied(string $ruleId, callable $operation): void
    {
        try {
            $operation();
            self::fail('Отзыв заявки должен быть запрещён');
        } catch (WithdrawDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
        }
    }
}

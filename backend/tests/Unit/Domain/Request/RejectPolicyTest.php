<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\RejectDenied;
use App\Domain\Request\RejectPolicy;
use App\Domain\Request\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RejectPolicyTest extends TestCase
{
    #[DataProvider('managerRoles')]
    public function testManagerCanReject(Role $managerRole): void
    {
        (new RejectPolicy())->assertCanReject([$managerRole], true);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{Role}> */
    public static function managerRoles(): iterable
    {
        yield 'руководитель ИЦ' => [Role::IcManager];
        yield 'руководитель лаборатории' => [Role::LaboratoryManager];
    }

    public function testExecutorCannotReject(): void
    {
        $this->expectDenied('WF-006', fn () => (new RejectPolicy())->assertCanReject([Role::IcExecutor], true));
    }

    public function testDisabledManagerCannotReject(): void
    {
        $this->expectDenied('AUTH-003', fn () => (new RejectPolicy())->assertCanReject([Role::IcManager], false));
    }

    private function expectDenied(string $ruleId, callable $operation): void
    {
        try {
            $operation();
            self::fail('Отказ в проведении испытаний должен быть запрещён');
        } catch (RejectDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
        }
    }
}

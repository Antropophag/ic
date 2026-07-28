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

    #[DataProvider('unauthorizedRoles')]
    public function testNonManagerCannotReject(Role $role): void
    {
        $this->expectDenied('WF-006', fn () => (new RejectPolicy())->assertCanReject([$role], true));
    }

    /** @return iterable<string, array{Role}> */
    public static function unauthorizedRoles(): iterable
    {
        yield 'сотрудник' => [Role::Employee];
        yield 'исполнитель ИЦ' => [Role::IcExecutor];
        yield 'эксперт' => [Role::Expert];
        yield 'сотрудник СБ' => [Role::SecurityOfficer];
        yield 'администратор' => [Role::Administrator];
    }

    #[DataProvider('managerRoles')]
    public function testDisabledManagerCannotReject(Role $managerRole): void
    {
        $this->expectDenied('AUTH-003', fn () => (new RejectPolicy())->assertCanReject([$managerRole], false));
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\AssignmentDenied;
use App\Domain\Request\AssignmentPolicy;
use App\Domain\Request\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssignmentPolicyTest extends TestCase
{
    #[DataProvider('managerRoles')]
    public function testManagerCanAssignActiveExecutor(Role $managerRole): void
    {
        (new AssignmentPolicy())->assertCanAssign([$managerRole], true, [Role::IcExecutor], true);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{Role}> */
    public static function managerRoles(): iterable
    {
        yield 'руководитель ИЦ' => [Role::IcManager];
        yield 'руководитель лаборатории' => [Role::LaboratoryManager];
    }

    public function testEmployeeCannotAssignExecutor(): void
    {
        $this->expectDenied('WF-001', fn () => (new AssignmentPolicy())->assertCanAssign(
            [Role::Employee],
            true,
            [Role::IcExecutor],
            true,
        ));
    }

    /** @param list<Role> $roles */
    #[DataProvider('invalidExecutors')]
    public function testInvalidExecutorIsRejected(bool $active, array $roles): void
    {
        $this->expectDenied('WF-002', fn () => (new AssignmentPolicy())->assertCanAssign(
            [Role::IcManager],
            $active,
            $roles,
            true,
        ));
    }

    public function testDisabledManagerCannotAssign(): void
    {
        $this->expectDenied('AUTH-003', fn () => (new AssignmentPolicy())->assertCanAssign(
            [Role::IcManager],
            true,
            [Role::IcExecutor],
            false,
        ));
    }

    /** @return iterable<string, array{bool, list<Role>}> */
    public static function invalidExecutors(): iterable
    {
        yield 'неактивный исполнитель' => [false, [Role::IcExecutor]];
        yield 'активный сотрудник без роли' => [true, [Role::Employee]];
    }

    private function expectDenied(string $ruleId, callable $operation): void
    {
        try {
            $operation();
            self::fail('Назначение должно быть запрещено');
        } catch (AssignmentDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
        }
    }
}

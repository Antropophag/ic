<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\RoleManagementDenied;
use App\Domain\Identity\RoleManagementPolicy;
use App\Domain\Request\Role;
use PHPUnit\Framework\TestCase;

final class RoleManagementPolicyTest extends TestCase
{
    public function testAdministratorCanManageRoles(): void
    {
        (new RoleManagementPolicy())->assertCanManage(true, [Role::Administrator]);
        self::addToAssertionCount(1);
    }

    public function testDisabledAdministratorCannotManageRoles(): void
    {
        $this->expectDenied('AUTH-003', fn () => (new RoleManagementPolicy())->assertCanManage(
            false,
            [Role::Administrator],
        ));
    }

    public function testIcManagerCannotManageRoles(): void
    {
        // Разграничение намеренное: право на процессные роли (WF-001 и т.д.)
        // не даёт права менять RBAC — иначе руководитель ИЦ мог бы сам себе
        // выдать любую роль.
        $this->expectDenied('AUTH-007', fn () => (new RoleManagementPolicy())->assertCanManage(
            true,
            [Role::IcManager, Role::LaboratoryManager],
        ));
    }

    public function testEmployeeWithoutAdministratorRoleIsRejected(): void
    {
        $this->expectDenied('AUTH-007', fn () => (new RoleManagementPolicy())->assertCanManage(
            true,
            [Role::Employee],
        ));
    }

    private function expectDenied(string $ruleId, callable $operation): void
    {
        try {
            $operation();
            self::fail('Управление ролями должно быть запрещено');
        } catch (RoleManagementDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
        }
    }
}

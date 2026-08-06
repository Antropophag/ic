<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\RequestCreationDenied;
use App\Domain\Request\RequestCreationPolicy;
use App\Domain\Request\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestCreationPolicyTest extends TestCase
{
    public function testActiveEmployeeCanCreate(): void
    {
        (new RequestCreationPolicy())->assertCanCreate([Role::Employee], true);
        self::addToAssertionCount(1);
    }

    public function testEmployeeWithExpertRoleCanCreate(): void
    {
        (new RequestCreationPolicy())->assertCanCreate([Role::Employee, Role::Expert], true);
        self::addToAssertionCount(1);
    }

    #[DataProvider('icRoles')]
    public function testIcStaffCannotCreate(Role $icRole): void
    {
        $this->expectDenied('REQ-001', fn () => (new RequestCreationPolicy())->assertCanCreate(
            [Role::Employee, $icRole],
            true,
        ));
    }

    /** @return iterable<string, array{Role}> */
    public static function icRoles(): iterable
    {
        yield 'исполнитель ИЦ' => [Role::IcExecutor];
        yield 'руководитель ИЦ' => [Role::IcManager];
        yield 'руководитель лаборатории' => [Role::LaboratoryManager];
    }

    public function testDisabledEmployeeCannotCreate(): void
    {
        $this->expectDenied('AUTH-003', fn () => (new RequestCreationPolicy())->assertCanCreate(
            [Role::Employee],
            false,
        ));
    }

    private function expectDenied(string $ruleId, callable $operation): void
    {
        try {
            $operation();
            self::fail('Создание заявки должно быть запрещено');
        } catch (RequestCreationDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
        }
    }
}

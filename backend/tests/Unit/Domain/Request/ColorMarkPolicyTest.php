<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\ColorMarkDenied;
use App\Domain\Request\ColorMarkPolicy;
use App\Domain\Request\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorMarkPolicyTest extends TestCase
{
    #[DataProvider('managerRoles')]
    public function testManagerCanSetColor(Role $managerRole): void
    {
        (new ColorMarkPolicy())->assertCanSetColor([$managerRole], true);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{Role}> */
    public static function managerRoles(): iterable
    {
        yield 'руководитель ИЦ' => [Role::IcManager];
        yield 'руководитель лаборатории' => [Role::LaboratoryManager];
    }

    public function testEmployeeCannotSetColor(): void
    {
        $this->expectDenied('WF-009', fn () => (new ColorMarkPolicy())->assertCanSetColor(
            [Role::Employee],
            true,
        ));
    }

    public function testExecutorCannotSetColor(): void
    {
        $this->expectDenied('WF-009', fn () => (new ColorMarkPolicy())->assertCanSetColor(
            [Role::IcExecutor],
            true,
        ));
    }

    public function testDisabledManagerCannotSetColor(): void
    {
        $this->expectDenied('AUTH-003', fn () => (new ColorMarkPolicy())->assertCanSetColor(
            [Role::IcManager],
            false,
        ));
    }

    private function expectDenied(string $ruleId, callable $operation): void
    {
        try {
            $operation();
            self::fail('Установка цветовой метки должна быть запрещена');
        } catch (ColorMarkDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
        }
    }
}

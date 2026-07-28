<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\Role;
use App\Domain\Request\StartDenied;
use App\Domain\Request\StartRequestPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StartRequestPolicyTest extends TestCase
{
    /** @param list<Role> $roles */
    #[DataProvider('allowedActors')]
    public function testAllowedActorCanStart(array $roles, bool $isCurrentExecutor): void
    {
        (new StartRequestPolicy())->assertCanStart($roles, $isCurrentExecutor, true);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{list<Role>, bool}> */
    public static function allowedActors(): iterable
    {
        yield 'назначенный исполнитель' => [[Role::IcExecutor], true];
        yield 'руководитель ИЦ' => [[Role::IcManager], false];
        yield 'руководитель лаборатории' => [[Role::LaboratoryManager], false];
    }

    /** @param list<Role> $roles */
    #[DataProvider('deniedActors')]
    public function testOtherActorIsDenied(array $roles, bool $isCurrentExecutor): void
    {
        try {
            (new StartRequestPolicy())->assertCanStart($roles, $isCurrentExecutor, true);
            self::fail('Запуск заявки должен быть запрещён');
        } catch (StartDenied $error) {
            self::assertSame('WF-004', $error->ruleId);
        }
    }

    /** @return iterable<string, array{list<Role>, bool}> */
    public static function deniedActors(): iterable
    {
        yield 'неназначенный исполнитель' => [[Role::IcExecutor], false];
        yield 'обычный сотрудник' => [[Role::Employee], false];
        yield 'назначенный пользователь без роли' => [[Role::Employee], true];
    }

    /** @param list<Role> $roles */
    #[DataProvider('disabledActors')]
    public function testDisabledActorIsDenied(array $roles, bool $isCurrentExecutor): void
    {
        try {
            (new StartRequestPolicy())->assertCanStart($roles, $isCurrentExecutor, false);
            self::fail('Отключённый пользователь не должен запускать заявку');
        } catch (StartDenied $error) {
            self::assertSame('AUTH-003', $error->ruleId);
        }
    }

    /** @return iterable<string, array{list<Role>, bool}> */
    public static function disabledActors(): iterable
    {
        yield 'отключённый руководитель' => [[Role::IcManager], false];
        yield 'отключённый назначенный исполнитель' => [[Role::IcExecutor], true];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\Role;
use App\Domain\Request\SuspendResumeDenied;
use App\Domain\Request\SuspendResumePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SuspendResumePolicyTest extends TestCase
{
    /** @param list<Role> $roles */
    #[DataProvider('allowedActors')]
    public function testAllowedActorCanSuspendAndResume(array $roles, bool $isCurrentExecutor): void
    {
        $policy = new SuspendResumePolicy();
        $policy->assertCanSuspend($roles, $isCurrentExecutor, true);
        $policy->assertCanResume($roles, $isCurrentExecutor, true);
        self::addToAssertionCount(2);
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
    public function testOtherActorIsDeniedForBothActions(array $roles, bool $isCurrentExecutor): void
    {
        $policy = new SuspendResumePolicy();

        try {
            $policy->assertCanSuspend($roles, $isCurrentExecutor, true);
            self::fail('Приостановка заявки должна быть запрещена');
        } catch (SuspendResumeDenied $error) {
            self::assertSame('WF-005', $error->ruleId);
        }

        try {
            $policy->assertCanResume($roles, $isCurrentExecutor, true);
            self::fail('Возобновление заявки должно быть запрещено');
        } catch (SuspendResumeDenied $error) {
            self::assertSame('WF-005', $error->ruleId);
        }
    }

    /** @return iterable<string, array{list<Role>, bool}> */
    public static function deniedActors(): iterable
    {
        yield 'неназначенный исполнитель' => [[Role::IcExecutor], false];
        yield 'обычный сотрудник' => [[Role::Employee], false];
        yield 'назначенный пользователь без роли' => [[Role::Employee], true];
    }

    public function testDisabledActorIsDenied(): void
    {
        $policy = new SuspendResumePolicy();

        try {
            $policy->assertCanSuspend([Role::IcManager], false, false);
            self::fail('Отключённый пользователь не должен приостанавливать заявку');
        } catch (SuspendResumeDenied $error) {
            self::assertSame('AUTH-003', $error->ruleId);
        }
    }
}

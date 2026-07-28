<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\ExpertAssignmentDenied;
use App\Domain\Request\ExpertAssignmentPolicy;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use PHPUnit\Framework\TestCase;

final class ExpertAssignmentPolicyTest extends TestCase
{
    public function testManagerCanAssignActiveExpertDuringOpinionPreparation(): void
    {
        (new ExpertAssignmentPolicy())->assertCanAssign(
            RequestStatus::OpinionPreparation,
            [Role::IcManager],
            true,
            [Role::Expert],
            true,
        );
        self::assertTrue(true);
    }

    /** @dataProvider deniedCases */
    public function testDeniedCases(string $ruleId, RequestStatus $status, array $actorRoles, bool $actorActive, array $expertRoles, bool $expertActive): void
    {
        $this->expectException(ExpertAssignmentDenied::class);
        $this->expectExceptionMessage($ruleId);
        (new ExpertAssignmentPolicy())->assertCanAssign($status, $actorRoles, $actorActive, $expertRoles, $expertActive);
    }

    public static function deniedCases(): iterable
    {
        yield 'неактивный руководитель' => ['AUTH-003', RequestStatus::OpinionPreparation, [Role::IcManager], false, [Role::Expert], true];
        yield 'обычный сотрудник' => ['WF-006', RequestStatus::OpinionPreparation, [Role::Employee], true, [Role::Expert], true];
        yield 'неверный этап' => ['DOC-005', RequestStatus::InProgress, [Role::IcManager], true, [Role::Expert], true];
        yield 'кандидат без роли' => ['WF-007', RequestStatus::OpinionPreparation, [Role::IcManager], true, [Role::Employee], true];
        yield 'неактивный эксперт' => ['WF-007', RequestStatus::OpinionPreparation, [Role::LaboratoryManager], true, [Role::Expert], false];
    }
}

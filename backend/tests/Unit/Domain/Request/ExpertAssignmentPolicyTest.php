<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\ExpertAssignmentDenied;
use App\Domain\Request\ExpertAssignmentPolicy;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpertAssignmentPolicyTest extends TestCase
{
    public function testManagerCanAssignActiveExpertDuringOpinionPreparation(): void
    {
        $this->expectNotToPerformAssertions();
        (new ExpertAssignmentPolicy())->assertCanAssign(
            RequestStatus::OpinionPreparation,
            [Role::IcManager],
            true,
            [Role::Expert],
            true,
        );
    }

    /**
     * @param list<Role> $actorRoles
     * @param list<Role> $expertRoles
     */
    #[DataProvider('deniedCases')]
    public function testDeniedCases(string $ruleId, RequestStatus $status, array $actorRoles, bool $actorActive, array $expertRoles, bool $expertActive): void
    {
        $this->expectException(ExpertAssignmentDenied::class);
        $this->expectExceptionMessage($ruleId);
        (new ExpertAssignmentPolicy())->assertCanAssign($status, $actorRoles, $actorActive, $expertRoles, $expertActive);
    }

    /** @return iterable<string, array{string, RequestStatus, list<Role>, bool, list<Role>, bool}> */
    public static function deniedCases(): iterable
    {
        yield 'неактивный руководитель' => ['AUTH-003', RequestStatus::OpinionPreparation, [Role::IcManager], false, [Role::Expert], true];
        yield 'обычный сотрудник' => ['WF-010', RequestStatus::OpinionPreparation, [Role::Employee], true, [Role::Expert], true];
        yield 'неверный этап' => ['DOC-005', RequestStatus::InProgress, [Role::IcManager], true, [Role::Expert], true];
        yield 'кандидат без роли' => ['WF-011', RequestStatus::OpinionPreparation, [Role::IcManager], true, [Role::Employee], true];
        yield 'неактивный эксперт' => ['WF-011', RequestStatus::OpinionPreparation, [Role::LaboratoryManager], true, [Role::Expert], false];
    }
}

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
    public function testActiveExpertCanClaimDuringOpinionPreparation(): void
    {
        $this->expectNotToPerformAssertions();
        (new ExpertAssignmentPolicy())->assertCanClaim(RequestStatus::OpinionPreparation, true, [Role::Expert]);
    }

    /** @param list<Role> $actorRoles */
    #[DataProvider('claimDeniedCases')]
    public function testClaimDeniedCases(string $ruleId, RequestStatus $status, bool $actorActive, array $actorRoles): void
    {
        $this->expectException(ExpertAssignmentDenied::class);
        $this->expectExceptionMessage($ruleId);
        (new ExpertAssignmentPolicy())->assertCanClaim($status, $actorActive, $actorRoles);
    }

    /** @return iterable<string, array{string, RequestStatus, bool, list<Role>}> */
    public static function claimDeniedCases(): iterable
    {
        yield 'неактивный эксперт' => ['AUTH-003', RequestStatus::OpinionPreparation, false, [Role::Expert]];
        yield 'не эксперт' => ['WF-010', RequestStatus::OpinionPreparation, true, [Role::Employee]];
        yield 'неверный этап' => ['DOC-005', RequestStatus::InProgress, true, [Role::Expert]];
    }

    public function testCurrentExpertCanReassignToActiveExpert(): void
    {
        $this->expectNotToPerformAssertions();
        (new ExpertAssignmentPolicy())->assertCanReassign(
            RequestStatus::OpinionPreparation,
            true,
            [Role::Expert],
            true,
            true,
            [Role::Expert],
        );
    }

    /**
     * @param list<Role> $actorRoles
     * @param list<Role> $targetRoles
     */
    #[DataProvider('reassignDeniedCases')]
    public function testReassignDeniedCases(
        string $ruleId,
        RequestStatus $status,
        bool $actorActive,
        array $actorRoles,
        bool $actorIsCurrentExpert,
        bool $targetActive,
        array $targetRoles,
    ): void {
        $this->expectException(ExpertAssignmentDenied::class);
        $this->expectExceptionMessage($ruleId);
        (new ExpertAssignmentPolicy())->assertCanReassign(
            $status,
            $actorActive,
            $actorRoles,
            $actorIsCurrentExpert,
            $targetActive,
            $targetRoles,
        );
    }

    /** @return iterable<string, array{string, RequestStatus, bool, list<Role>, bool, bool, list<Role>}> */
    public static function reassignDeniedCases(): iterable
    {
        yield 'неактивный эксперт' => [
            'AUTH-003', RequestStatus::OpinionPreparation, false, [Role::Expert], true, true, [Role::Expert],
        ];
        yield 'не текущий эксперт заявки' => [
            'WF-011', RequestStatus::OpinionPreparation, true, [Role::Expert], false, true, [Role::Expert],
        ];
        yield 'роль эксперта отозвана' => [
            'WF-011', RequestStatus::OpinionPreparation, true, [Role::Employee], true, true, [Role::Expert],
        ];
        yield 'неверный этап' => [
            'DOC-005', RequestStatus::InProgress, true, [Role::Expert], true, true, [Role::Expert],
        ];
        yield 'кандидат без роли эксперта' => [
            'WF-011', RequestStatus::OpinionPreparation, true, [Role::Expert], true, true, [Role::Employee],
        ];
        yield 'неактивный кандидат' => [
            'WF-011', RequestStatus::OpinionPreparation, true, [Role::Expert], true, false, [Role::Expert],
        ];
    }
}

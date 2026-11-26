<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\CloseRequestPolicy;
use App\Domain\Request\RequestRouteDenied;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CloseRequestPolicyTest extends TestCase
{
    public function testBothManagersCanSelectAllThreeOutcomes(): void
    {
        foreach ([Role::IcManager, Role::LaboratoryManager] as $role) {
            foreach ([[true, true], [true, false], [false, false]] as [$expertise, $security]) {
                (new CloseRequestPolicy())->assertCanClose(RequestStatus::InProgress, true, true, [$role], $expertise, $security);
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('deniedClosures')]
    public function testClosureBoundary(RequestStatus $status, bool $report, bool $active, Role $role, bool $expertise, bool $security): void
    {
        $this->expectException(RequestRouteDenied::class);
        (new CloseRequestPolicy())->assertCanClose($status, $report, $active, [$role], $expertise, $security);
    }

    /** @return iterable<string, array{RequestStatus, bool, bool, Role, bool, bool}> */
    public static function deniedClosures(): iterable
    {
        yield 'report missing' => [RequestStatus::InProgress, false, true, Role::IcManager, true, true];
        yield 'security without expertise' => [RequestStatus::InProgress, true, true, Role::IcManager, false, true];
        yield 'suspended' => [RequestStatus::Suspended, true, true, Role::IcManager, true, true];
        yield 'already submitted' => [RequestStatus::OpinionPreparation, true, true, Role::IcManager, false, false];
        yield 'repeat' => [RequestStatus::Completed, true, true, Role::IcManager, true, true];
        yield 'expert' => [RequestStatus::InProgress, true, true, Role::Expert, true, true];
        yield 'executor' => [RequestStatus::InProgress, true, true, Role::IcExecutor, false, false];
        yield 'inactive' => [RequestStatus::InProgress, true, false, Role::LaboratoryManager, true, true];
    }
}

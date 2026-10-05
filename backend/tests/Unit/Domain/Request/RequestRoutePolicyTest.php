<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\RequestRoute;
use App\Domain\Request\RequestRouteDenied;
use App\Domain\Request\RequestRoutePolicy;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestRoutePolicyTest extends TestCase
{
    public function testBothManagersChooseChangeAndCompleteAct(): void
    {
        foreach ([Role::IcManager, Role::LaboratoryManager] as $role) {
            $policy = new RequestRoutePolicy();
            $policy->assertCanChoose(RequestStatus::Registered, null, RequestRoute::Act, false, true, [$role]);
            $policy->assertCanChoose(RequestStatus::InProgress, RequestRoute::Act, RequestRoute::Protocol, false, true, [$role]);
            $policy->assertCanComplete(RequestStatus::InProgress, RequestRoute::Act, true, true, [$role]);
        }
        $this->addToAssertionCount(6);
    }

    #[DataProvider('deniedSelections')]
    public function testSelectionBoundary(RequestStatus $status, ?RequestRoute $current, bool $report, bool $active, Role $role): void
    {
        $this->expectException(RequestRouteDenied::class);
        (new RequestRoutePolicy())->assertCanChoose($status, $current, RequestRoute::Act, $report, $active, [$role]);
    }

    /** @return iterable<string, array{RequestStatus, ?RequestRoute, bool, bool, Role}> */
    public static function deniedSelections(): iterable
    {
        yield 'already chosen' => [RequestStatus::Registered, RequestRoute::Act, false, true, Role::IcManager];
        yield 'first report freezes route' => [RequestStatus::InProgress, RequestRoute::Protocol, true, true, Role::IcManager];
        yield 'first choice before work only' => [RequestStatus::InProgress, null, false, true, Role::IcManager];
        yield 'completed' => [RequestStatus::Completed, RequestRoute::Protocol, false, true, Role::IcManager];
        yield 'expert' => [RequestStatus::Registered, null, false, true, Role::Expert];
        yield 'inactive' => [RequestStatus::Registered, null, false, false, Role::LaboratoryManager];
    }

    #[DataProvider('deniedCompletions')]
    public function testCompletionBoundary(RequestStatus $status, ?RequestRoute $route, bool $report, bool $active, Role $role): void
    {
        $this->expectException(RequestRouteDenied::class);
        (new RequestRoutePolicy())->assertCanComplete($status, $route, $report, $active, [$role]);
    }

    /** @return iterable<string, array{RequestStatus, ?RequestRoute, bool, bool, Role}> */
    public static function deniedCompletions(): iterable
    {
        yield 'report missing' => [RequestStatus::InProgress, RequestRoute::Act, false, true, Role::IcManager];
        yield 'protocol bypass' => [RequestStatus::InProgress, RequestRoute::Protocol, true, true, Role::IcManager];
        yield 'route missing' => [RequestStatus::InProgress, null, true, true, Role::IcManager];
        yield 'suspended' => [RequestStatus::Suspended, RequestRoute::Act, true, true, Role::IcManager];
        yield 'repeat' => [RequestStatus::Completed, RequestRoute::Act, true, true, Role::IcManager];
        yield 'expert' => [RequestStatus::InProgress, RequestRoute::Act, true, true, Role::Expert];
        yield 'executor' => [RequestStatus::InProgress, RequestRoute::Act, true, true, Role::IcExecutor];
        yield 'inactive' => [RequestStatus::InProgress, RequestRoute::Act, true, false, Role::LaboratoryManager];
    }
}

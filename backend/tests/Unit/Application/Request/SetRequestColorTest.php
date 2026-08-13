<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\SetRequestColorCommand;
use App\Application\Request\UseCase\SetRequestColor;
use App\Domain\Request\ColorMarkDenied;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestColor;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\Role;
use PHPUnit\Framework\TestCase;

final class SetRequestColorTest extends TestCase
{
    public function testManagerSetsColorAndRecordsSuccessfulAudit(): void
    {
        $gateway = new InMemoryRequestColorGateway(4, [Role::LaboratoryManager], true);

        $result = (new SetRequestColor($gateway))->execute(
            new SetRequestColorCommand(17, RequestColor::Violet, 4, 23),
        );

        self::assertSame(17, $result->requestId);
        self::assertSame(RequestColor::Violet, $result->color);
        self::assertSame(5, $result->lockVersion);
        self::assertSame(RequestColor::Violet, $gateway->persistedColor);
        self::assertSame(5, $gateway->persistedLockVersion);
        self::assertSame([
            'requestId' => 17,
            'actorId' => 23,
            'color' => RequestColor::Violet,
            'ruleId' => 'WF-009',
        ], $gateway->audit);
    }

    public function testInactiveManagerIsDeniedWithoutMutation(): void
    {
        $gateway = new InMemoryRequestColorGateway(4, [Role::IcManager], false);

        $this->expectDenied('AUTH-003', $gateway);
    }

    public function testActorWithoutManagerRoleIsDeniedWithoutMutation(): void
    {
        $gateway = new InMemoryRequestColorGateway(4, [Role::Employee], true);

        $this->expectDenied('WF-009', $gateway);
    }

    public function testStaleLockVersionPreservesState(): void
    {
        $gateway = new InMemoryRequestColorGateway(5, [Role::IcManager], true);

        try {
            (new SetRequestColor($gateway))->execute($this->command());
            self::fail('Expected optimistic-lock conflict.');
        } catch (ConcurrentRequestModification $error) {
            self::assertSame('WF-003', $error->ruleId);
        }

        self::assertNull($gateway->persistedColor);
        self::assertNull($gateway->persistedLockVersion);
        self::assertNull($gateway->audit);
    }

    public function testMissingRequestIsPreserved(): void
    {
        $gateway = new InMemoryRequestColorGateway(null, [Role::IcManager], true);

        $this->expectException(RequestNotFound::class);
        (new SetRequestColor($gateway))->execute($this->command());
    }

    private function expectDenied(string $ruleId, InMemoryRequestColorGateway $gateway): void
    {
        try {
            (new SetRequestColor($gateway))->execute($this->command());
            self::fail('Expected color mark denial.');
        } catch (ColorMarkDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
        }

        self::assertNull($gateway->persistedColor);
        self::assertNull($gateway->persistedLockVersion);
        self::assertNull($gateway->audit);
    }

    private function command(): SetRequestColorCommand
    {
        return new SetRequestColorCommand(17, RequestColor::Red, 4, 23);
    }
}

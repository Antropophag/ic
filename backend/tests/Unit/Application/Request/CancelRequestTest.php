<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\CancelRequestCommand;
use App\Application\Request\RequestCancellationSnapshot;
use App\Application\Request\UseCase\CancelRequest;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RejectDenied;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use App\Domain\Request\WithdrawDenied;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CancelRequestTest extends TestCase
{
    /** @param list<Role> $roles */
    #[DataProvider('successfulCancellationProvider')]
    public function testPersistsCompleteCancellation(
        RequestAction $action,
        RequestStatus $from,
        RequestStatus $to,
        array $roles,
        int $actorId,
    ): void {
        $gateway = new InMemoryRequestCancellationGateway(
            new RequestCancellationSnapshot($from, 4, 23, false),
            $roles,
        );
        $result = (new CancelRequest($gateway))->execute(
            new CancelRequestCommand(17, 4, $actorId, $action, 'Причина'),
        );

        self::assertSame($to, $result->status);
        self::assertSame($action, $gateway->snapshotAction);
        self::assertSame(5, $result->lockVersion);
        self::assertTrue($gateway->persisted);
        self::assertTrue($gateway->transitionRecorded);
        self::assertTrue($gateway->auditRecorded);
        self::assertTrue($gateway->notificationsEnqueued);
    }

    /** @return iterable<string, array{RequestAction, RequestStatus, RequestStatus, list<Role>, int}> */
    public static function successfulCancellationProvider(): iterable
    {
        yield 'reject' => [RequestAction::Reject, RequestStatus::Registered, RequestStatus::Rejected, [Role::IcManager], 9];
        yield 'withdraw' => [RequestAction::Withdraw, RequestStatus::OpinionPreparation, RequestStatus::Withdrawn, [], 23];
    }

    public function testRejectRequiresManager(): void
    {
        $this->expectException(RejectDenied::class);
        $this->execute(RequestAction::Reject, RequestStatus::Registered, [Role::Employee], 23);
    }

    public function testWithdrawRequiresInitiator(): void
    {
        $this->expectException(WithdrawDenied::class);
        $this->execute(RequestAction::Withdraw, RequestStatus::Registered, [], 24);
    }

    public function testForbiddenTransitionIsPreserved(): void
    {
        $this->expectException(ConcurrentRequestModification::class);
        $this->execute(RequestAction::Reject, RequestStatus::Suspended, [Role::IcManager], 23);
    }

    public function testForbiddenTransitionPrecedesAuthorization(): void
    {
        $this->expectException(ConcurrentRequestModification::class);
        $this->execute(RequestAction::Reject, RequestStatus::Suspended, [Role::Employee], 23);
    }

    public function testMissingRequestIsReported(): void
    {
        $this->expectException(RequestNotFound::class);
        (new CancelRequest(new InMemoryRequestCancellationGateway(null)))->execute(
            new CancelRequestCommand(17, 4, 23, RequestAction::Withdraw, 'Причина'),
        );
    }

    #[DataProvider('conflictProvider')]
    public function testStaleOrPreviouslyReviewedWithdrawIsConflict(int $version, bool $reviewed): void
    {
        $gateway = new InMemoryRequestCancellationGateway(
            new RequestCancellationSnapshot(RequestStatus::Registered, $version, 23, $reviewed),
        );
        $this->expectException(ConcurrentRequestModification::class);
        (new CancelRequest($gateway))->execute(
            new CancelRequestCommand(17, 4, 23, RequestAction::Withdraw, 'Причина'),
        );
    }

    /** @return iterable<string, array{int, bool}> */
    public static function conflictProvider(): iterable
    {
        yield 'stale' => [5, false];
        yield 'reviewed' => [4, true];
    }

    /** @param list<Role> $roles */
    private function execute(RequestAction $action, RequestStatus $status, array $roles, int $actorId): void
    {
        $gateway = new InMemoryRequestCancellationGateway(
            new RequestCancellationSnapshot($status, 4, 23, false),
            $roles,
        );
        (new CancelRequest($gateway))->execute(new CancelRequestCommand(17, 4, $actorId, $action, 'Причина'));
    }
}

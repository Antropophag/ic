<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\RequestLifecycleCommand;
use App\Application\Request\RequestLifecycleSnapshot;
use App\Application\Request\UseCase\RequestLifecycle;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use App\Domain\Request\StartDenied;
use App\Domain\Request\TransitionDenied;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestLifecycleTest extends TestCase
{
    #[DataProvider('transitionProvider')]
    public function testTransitionsPreserveWorkflowContract(
        RequestStatus $from,
        RequestAction $action,
        RequestStatus $to,
        string $ruleId,
        ?string $reason,
    ): void {
        $gateway = new InMemoryRequestLifecycleGateway(
            new RequestLifecycleSnapshot($from, 4),
            [Role::IcManager],
        );

        $result = (new RequestLifecycle($gateway))->execute(
            new RequestLifecycleCommand(17, 4, 23, $action, $reason),
        );

        self::assertSame($to, $result->status);
        self::assertSame(5, $result->lockVersion);
        self::assertSame($ruleId, $gateway->transition['ruleId']);
        self::assertSame($reason, $gateway->transition['reason']);
        self::assertSame($gateway->transition['changedAt'], $gateway->audit['changedAt']);
    }

    /** @return iterable<string, array{RequestStatus, RequestAction, RequestStatus, string, string|null}> */
    public static function transitionProvider(): iterable
    {
        yield 'start' => [RequestStatus::Registered, RequestAction::Start, RequestStatus::InProgress, 'WF-004', null];
        yield 'suspend' => [RequestStatus::InProgress, RequestAction::Suspend, RequestStatus::Suspended, 'WF-005', 'Причина'];
        yield 'resume' => [RequestStatus::Suspended, RequestAction::Resume, RequestStatus::InProgress, 'WF-005', null];
    }

    public function testAssignedExecutorCanStart(): void
    {
        $gateway = new InMemoryRequestLifecycleGateway(
            new RequestLifecycleSnapshot(RequestStatus::Registered, 4),
            [Role::IcExecutor],
            true,
        );

        (new RequestLifecycle($gateway))->execute($this->command(RequestAction::Start));

        self::assertNotNull($gateway->transition);
    }

    public function testUnauthorizedStartDoesNotMutate(): void
    {
        $gateway = new InMemoryRequestLifecycleGateway(
            new RequestLifecycleSnapshot(RequestStatus::Registered, 4),
            [Role::IcExecutor],
        );

        try {
            (new RequestLifecycle($gateway))->execute($this->command(RequestAction::Start));
            self::fail('Expected start denial.');
        } catch (StartDenied $error) {
            self::assertSame('WF-004', $error->ruleId);
        }
        self::assertNull($gateway->transition);
    }

    public function testForbiddenTransitionDoesNotMutate(): void
    {
        $gateway = new InMemoryRequestLifecycleGateway(
            new RequestLifecycleSnapshot(RequestStatus::Registered, 4),
            [Role::IcManager],
        );

        $this->expectException(TransitionDenied::class);
        (new RequestLifecycle($gateway))->execute($this->command(RequestAction::Resume));
    }

    public function testStaleVersionDoesNotMutate(): void
    {
        $gateway = new InMemoryRequestLifecycleGateway(
            new RequestLifecycleSnapshot(RequestStatus::Registered, 5),
            [Role::IcManager],
        );

        $this->expectException(ConcurrentRequestModification::class);
        (new RequestLifecycle($gateway))->execute($this->command(RequestAction::Start));
    }

    public function testMissingRequestIsPreserved(): void
    {
        $gateway = new InMemoryRequestLifecycleGateway(null, [Role::IcManager]);

        $this->expectException(RequestNotFound::class);
        (new RequestLifecycle($gateway))->execute($this->command(RequestAction::Start));
    }

    public function testConditionalUpdateFailureIsAConflict(): void
    {
        $gateway = new InMemoryRequestLifecycleGateway(
            new RequestLifecycleSnapshot(RequestStatus::Registered, 4),
            [Role::IcManager],
            updateSucceeds: false,
        );

        $this->expectException(ConcurrentRequestModification::class);
        (new RequestLifecycle($gateway))->execute($this->command(RequestAction::Start));
    }

    public function testCommandRejectsActionsOutsideLifecycleSlice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RequestLifecycleCommand(17, 4, 23, RequestAction::Reject);
    }

    private function command(RequestAction $action): RequestLifecycleCommand
    {
        return new RequestLifecycleCommand(17, 4, 23, $action);
    }
}

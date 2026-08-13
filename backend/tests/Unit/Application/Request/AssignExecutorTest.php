<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\AssignExecutorCommand;
use App\Application\Request\ExecutorAssignmentSnapshot;
use App\Application\Request\UseCase\AssignExecutor;
use App\Domain\Request\AssignmentDenied;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssignExecutorTest extends TestCase
{
    #[DataProvider('allowedStatusProvider')]
    public function testAssignsInAllowedStatuses(RequestStatus $status): void
    {
        $gateway = $this->gateway($status);

        $result = (new AssignExecutor($gateway))->execute($this->command());

        self::assertSame([
            'id' => 91,
            'requestId' => 17,
            'executorId' => 29,
            'assignedBy' => 23,
            'assignedAt' => '2026-08-14 12:00:00.000000',
            'lockVersion' => 5,
        ], $result->toArray());
        self::assertTrue($gateway->closed);
        self::assertSame(5, $gateway->audit['lockVersion']);
        self::assertSame($status, $gateway->notification['status']);
    }

    /** @return iterable<string, array{RequestStatus}> */
    public static function allowedStatusProvider(): iterable
    {
        yield 'registered' => [RequestStatus::Registered];
        yield 'in progress' => [RequestStatus::InProgress];
        yield 'suspended' => [RequestStatus::Suspended];
    }

    public function testMissingRequestIsNotFound(): void
    {
        $this->expectException(AssignmentTargetNotFound::class);
        (new AssignExecutor(new InMemoryExecutorAssignmentGateway(null)))->execute($this->command());
    }

    public function testMissingExecutorIsNotFound(): void
    {
        $gateway = $this->gateway();
        $gateway->executorExists = false;
        $this->expectException(AssignmentTargetNotFound::class);
        (new AssignExecutor($gateway))->execute($this->command());
    }

    #[DataProvider('conflictProvider')]
    public function testStatusVersionAndConditionalUpdateConflicts(
        RequestStatus $status,
        int $version,
        bool $updateSucceeds,
    ): void {
        $gateway = new InMemoryExecutorAssignmentGateway(new ExecutorAssignmentSnapshot($status, $version));
        $gateway->updateSucceeds = $updateSucceeds;

        try {
            (new AssignExecutor($gateway))->execute($this->command());
            self::fail('Expected assignment conflict.');
        } catch (ConcurrentRequestModification) {
            self::assertFalse($gateway->closed);
            self::assertNull($gateway->audit);
        }
    }

    /** @return iterable<string, array{RequestStatus, int, bool}> */
    public static function conflictProvider(): iterable
    {
        yield 'disallowed status' => [RequestStatus::OpinionPreparation, 4, true];
        yield 'stale version' => [RequestStatus::Registered, 5, true];
        yield 'conditional update lost' => [RequestStatus::Registered, 4, false];
    }

    #[DataProvider('deniedProvider')]
    public function testAuthorizationAndExecutorEligibilityRemainDenied(string $case, string $ruleId): void
    {
        $gateway = $this->gateway();
        match ($case) {
            'inactive actor' => $gateway->actorActive = false,
            'wrong actor role' => $gateway->actorRoles = [Role::Employee],
            'inactive executor' => $gateway->executorActive = false,
            'wrong executor role' => $gateway->executorRoles = [Role::Employee],
            'same executor' => $gateway->currentExecutor = true,
            default => throw new \InvalidArgumentException("Unknown denial case {$case}"),
        };

        try {
            (new AssignExecutor($gateway))->execute($this->command());
            self::fail('Expected assignment denial.');
        } catch (AssignmentDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
            self::assertFalse($gateway->closed);
            self::assertNull($gateway->audit);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function deniedProvider(): iterable
    {
        yield 'inactive actor' => ['inactive actor', 'AUTH-003'];
        yield 'wrong actor role' => ['wrong actor role', 'WF-001'];
        yield 'inactive executor' => ['inactive executor', 'WF-002'];
        yield 'wrong executor role' => ['wrong executor role', 'WF-002'];
        yield 'same executor' => ['same executor', 'WF-013'];
    }

    public function testRejectedAssignmentDelegatesAuditWithoutMutation(): void
    {
        $gateway = $this->gateway();
        (new AssignExecutor($gateway))->recordRejected($this->command(), 'WF-002');

        self::assertSame([
            'requestId' => 17,
            'executorId' => 29,
            'actorId' => 23,
            'ruleId' => 'WF-002',
        ], $gateway->deniedAudit);
        self::assertFalse($gateway->closed);
    }

    private function gateway(RequestStatus $status = RequestStatus::Registered): InMemoryExecutorAssignmentGateway
    {
        return new InMemoryExecutorAssignmentGateway(new ExecutorAssignmentSnapshot($status, 4));
    }

    private function command(): AssignExecutorCommand
    {
        return new AssignExecutorCommand(17, 29, 4, 23);
    }
}

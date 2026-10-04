<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\AssignExpertCommand;
use App\Application\Request\Command\ExpertAssignmentAction;
use App\Application\Request\ExpertAssignmentSnapshot;
use App\Application\Request\UseCase\AssignExpert;
use App\Domain\Request\AssignmentTargetNotFound;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\ExpertAssignmentDenied;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssignExpertTest extends TestCase
{
    public function testClaimAssignsActorWithoutNotification(): void
    {
        $gateway = $this->gateway(false);
        $result = (new AssignExpert($gateway))->execute($this->command(ExpertAssignmentAction::Claim, 23));

        self::assertSame(23, $result->expertId);
        self::assertSame(5, $result->lockVersion);
        self::assertSame(ExpertAssignmentAction::Claim, $gateway->audit['action']);
        self::assertTrue($gateway->closed);
        self::assertFalse($gateway->notified);
    }

    public function testClaimCannotAssignAnotherExpert(): void
    {
        $gateway = $this->gateway(false);

        try {
            (new AssignExpert($gateway))->execute($this->command(ExpertAssignmentAction::Claim, 29));
            self::fail('Expected non-self claim denial.');
        } catch (ExpertAssignmentDenied $error) {
            self::assertSame('WF-010', $error->ruleId);
            self::assertFalse($gateway->closed);
            self::assertNull($gateway->audit);
        }
    }

    public function testReassignsNewExpertAndNotifies(): void
    {
        $gateway = $this->gateway(true);
        $result = (new AssignExpert($gateway))->execute($this->command(ExpertAssignmentAction::Reassign, 29));

        self::assertSame(29, $result->expertId);
        self::assertSame(ExpertAssignmentAction::Reassign, $gateway->audit['action']);
        self::assertTrue($gateway->notified);
    }

    public function testMissingRequestAndExpertRemainNotFound(): void
    {
        try {
            (new AssignExpert(new InMemoryExpertAssignmentGateway(null)))->execute(
                $this->command(ExpertAssignmentAction::Claim, 23),
            );
            self::fail('Expected missing request.');
        } catch (AssignmentTargetNotFound $error) {
            self::assertSame('Request not found', $error->getMessage());
        }

        $gateway = $this->gateway(true);
        $gateway->expertExists = false;
        $this->expectException(AssignmentTargetNotFound::class);
        (new AssignExpert($gateway))->execute($this->command(ExpertAssignmentAction::Reassign, 29));
    }

    #[DataProvider('conflictProvider')]
    public function testStaleVersionInvalidStatusAndConditionalUpdateRemainConflicts(
        RequestStatus $status,
        int $version,
        bool $updateSucceeds,
        bool $current,
    ): void {
        $gateway = new InMemoryExpertAssignmentGateway(new ExpertAssignmentSnapshot($status, $version, $current));
        $gateway->updateSucceeds = $updateSucceeds;

        $this->expectException(ConcurrentRequestModification::class);
        (new AssignExpert($gateway))->execute($this->command(ExpertAssignmentAction::Claim, 23));
    }

    /** @return iterable<string, array{RequestStatus, int, bool, bool}> */
    public static function conflictProvider(): iterable
    {
        yield 'stale version' => [RequestStatus::OpinionPreparation, 5, true, false];
        yield 'conditional update' => [RequestStatus::OpinionPreparation, 4, false, false];
    }

    #[DataProvider('deniedProvider')]
    public function testAuthorizationStatusAndTargetConstraintsRemainDenied(string $case, string $ruleId): void
    {
        $action = str_starts_with($case, 'claim') ? ExpertAssignmentAction::Claim : ExpertAssignmentAction::Reassign;
        $gateway = $this->gateway($action === ExpertAssignmentAction::Reassign);
        match ($case) {
            'claim inactive' => $gateway->actorActive = false,
            'claim role' => $gateway->actorRoles = [Role::Employee],
            'claim current' => $gateway->snapshot = new ExpertAssignmentSnapshot(RequestStatus::OpinionPreparation, 4, true),
            'claim status' => $gateway->snapshot = new ExpertAssignmentSnapshot(RequestStatus::InProgress, 4, false),
            'reassign inactive' => $gateway->actorActive = false,
            'reassign role' => $gateway->actorRoles = [Role::Employee],
            'reassign not current' => $gateway->snapshot = new ExpertAssignmentSnapshot(RequestStatus::OpinionPreparation, 4, false),
            'reassign status' => $gateway->snapshot = new ExpertAssignmentSnapshot(RequestStatus::InProgress, 4, true),
            'reassign self' => null,
            'reassign inactive target' => $gateway->expertActive = false,
            'reassign target role' => $gateway->expertRoles = [Role::Employee],
            default => throw new \InvalidArgumentException($case),
        };
        $expertId = $case === 'reassign self' ? 23 : ($action === ExpertAssignmentAction::Claim ? 23 : 29);

        try {
            (new AssignExpert($gateway))->execute($this->command($action, $expertId));
            self::fail('Expected denial.');
        } catch (ExpertAssignmentDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
            self::assertFalse($gateway->closed);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function deniedProvider(): iterable
    {
        yield 'claim inactive' => ['claim inactive', 'AUTH-003'];
        yield 'claim role' => ['claim role', 'WF-010'];
        yield 'claim current' => ['claim current', 'WF-010'];
        yield 'claim status' => ['claim status', 'DOC-005'];
        yield 'reassign inactive' => ['reassign inactive', 'AUTH-003'];
        yield 'reassign role' => ['reassign role', 'WF-011'];
        yield 'reassign not current' => ['reassign not current', 'WF-011'];
        yield 'reassign status' => ['reassign status', 'DOC-005'];
        yield 'reassign self' => ['reassign self', 'WF-011'];
        yield 'reassign inactive target' => ['reassign inactive target', 'WF-011'];
        yield 'reassign target role' => ['reassign target role', 'WF-011'];
    }

    public function testRejectedAssignmentDelegatesDeniedAudit(): void
    {
        $gateway = $this->gateway(false);
        $command = $this->command(ExpertAssignmentAction::Claim, 23);
        (new AssignExpert($gateway))->recordRejected($command, 'WF-010');
        self::assertSame(['requestId' => 17, 'expertId' => 23, 'actorId' => 23, 'ruleId' => 'WF-010'], $gateway->deniedAudit);
    }

    private function gateway(bool $current): InMemoryExpertAssignmentGateway
    {
        return new InMemoryExpertAssignmentGateway(
            new ExpertAssignmentSnapshot(RequestStatus::OpinionPreparation, 4, $current),
        );
    }

    private function command(ExpertAssignmentAction $action, int $expertId): AssignExpertCommand
    {
        return new AssignExpertCommand($action, 17, $expertId, 4, 23);
    }
}

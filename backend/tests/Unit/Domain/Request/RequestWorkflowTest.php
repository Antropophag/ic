<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\RequestAction;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\RequestWorkflow;
use App\Domain\Request\Role;
use App\Domain\Request\TransitionDenied;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestWorkflowTest extends TestCase
{
    #[DataProvider('allowedTransitions')]
    public function testAllowedTransition(
        RequestStatus $from,
        RequestAction $action,
        Role $role,
        RequestStatus $expected,
    ): void {
        self::assertSame($expected, (new RequestWorkflow())->transition($from, $action, [$role]));
    }

    /** @return iterable<string, array{RequestStatus, RequestAction, Role, RequestStatus}> */
    public static function allowedTransitions(): iterable
    {
        yield 'executor starts' => [RequestStatus::Registered, RequestAction::Start, Role::IcExecutor, RequestStatus::InProgress];
        yield 'executor uploads report' => [RequestStatus::InProgress, RequestAction::UploadReport, Role::IcExecutor, RequestStatus::OpinionPreparation];
        yield 'expert publishes' => [RequestStatus::OpinionPreparation, RequestAction::PublishOpinion, Role::Expert, RequestStatus::SecurityReview];
        yield 'security approves' => [RequestStatus::SecurityReview, RequestAction::SecurityApprove, Role::SecurityOfficer, RequestStatus::Completed];
        yield 'security returns' => [RequestStatus::SecurityReview, RequestAction::SecurityReturn, Role::SecurityOfficer, RequestStatus::InProgress];
    }

    public function testEmployeeCannotCompleteRegisteredRequest(): void
    {
        $this->expectException(TransitionDenied::class);
        $this->expectExceptionMessage('WF-003');

        (new RequestWorkflow())->transition(
            RequestStatus::Registered,
            RequestAction::SecurityApprove,
            [Role::Employee],
        );
    }

    public function testWrongRoleReportsSpecificBusinessRule(): void
    {
        try {
            (new RequestWorkflow())->transition(
                RequestStatus::OpinionPreparation,
                RequestAction::PublishOpinion,
                [Role::IcExecutor],
            );
            self::fail('Transition must be denied');
        } catch (TransitionDenied $error) {
            self::assertSame('DOC-005', $error->ruleId);
        }
    }
}

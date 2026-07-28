<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\ReportDenied;
use App\Domain\Request\ReportPolicy;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportPolicyTest extends TestCase
{
    public function testAllowsAssignedExecutorInProgress(): void
    {
        (new ReportPolicy())->assertCanUpload(RequestStatus::InProgress, true, false);
        self::addToAssertionCount(1);
    }

    public function testAllowsManagerToAddRevisionDuringOpinionPreparation(): void
    {
        (new ReportPolicy())->assertCanUpload(RequestStatus::OpinionPreparation, false, true);
        self::addToAssertionCount(1);
    }

    #[DataProvider('deniedUploads')]
    public function testRejectsWrongActorOrStatus(RequestStatus $status, bool $executor, bool $manager): void
    {
        $this->expectException(ReportDenied::class);
        (new ReportPolicy())->assertCanUpload($status, $executor, $manager);
    }

    /** @return iterable<string, array{RequestStatus, bool, bool}> */
    public static function deniedUploads(): iterable
    {
        yield 'other employee' => [RequestStatus::InProgress, false, false];
        yield 'registered' => [RequestStatus::Registered, true, false];
        yield 'security review' => [RequestStatus::SecurityReview, false, true];
    }

    public function testOnlyAcceptsPdf(): void
    {
        (new ReportPolicy())->assertValidFile('report.pdf', 'application/pdf', 100);
        self::addToAssertionCount(1);

        try {
            (new ReportPolicy())->assertValidFile(
                'report.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                100,
            );
            self::fail('Invalid report must be rejected.');
        } catch (ReportDenied $error) {
            self::assertSame('DOC-002A', $error->ruleId);
        }
    }
}

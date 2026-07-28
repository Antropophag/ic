<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use App\Domain\Request\SecurityDecisionDenied;
use App\Domain\Request\SecurityDecisionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityDecisionPolicyTest extends TestCase
{
    public function testApprovesAndReturnsRequest(): void
    {
        $policy = new SecurityDecisionPolicy();
        self::assertSame(RequestStatus::Completed, $policy->targetStatus(
            RequestStatus::SecurityReview,
            'approve',
            null,
            true,
            [Role::SecurityOfficer],
        ));
        self::assertSame(RequestStatus::InProgress, $policy->targetStatus(
            RequestStatus::SecurityReview,
            'return',
            'Нужны повторные испытания',
            true,
            [Role::SecurityOfficer],
        ));
    }

    /** @param list<Role> $roles */
    #[DataProvider('deniedCases')]
    public function testRejectsInvalidDecision(
        string $ruleId,
        RequestStatus $status,
        string $decision,
        ?string $reason,
        bool $active,
        array $roles,
    ): void {
        $this->expectException(SecurityDecisionDenied::class);
        try {
            (new SecurityDecisionPolicy())->targetStatus($status, $decision, $reason, $active, $roles);
        } catch (SecurityDecisionDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
            throw $error;
        }
    }

    /** @return iterable<string, array{string, RequestStatus, string, string|null, bool, list<Role>}> */
    public static function deniedCases(): iterable
    {
        yield 'inactive' => ['AUTH-003', RequestStatus::SecurityReview, 'approve', null, false, [Role::SecurityOfficer]];
        yield 'wrong status' => ['SEC-001', RequestStatus::InProgress, 'approve', null, true, [Role::SecurityOfficer]];
        yield 'wrong role' => ['SEC-001', RequestStatus::SecurityReview, 'approve', null, true, [Role::Employee]];
        yield 'reason required' => ['SEC-003', RequestStatus::SecurityReview, 'return', ' ', true, [Role::SecurityOfficer]];
        yield 'unknown decision' => ['SEC-001', RequestStatus::SecurityReview, 'skip', null, true, [Role::SecurityOfficer]];
    }
}

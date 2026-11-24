<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\OpinionDeletionPolicy;
use App\Domain\Request\OpinionDenied;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Role;
use PHPUnit\Framework\TestCase;

final class OpinionDeletionPolicyTest extends TestCase
{
    public function testOnlyManagersAndExpertsCanDeleteWithRequiredDocuments(): void
    {
        foreach (Role::cases() as $role) {
            try {
                (new OpinionDeletionPolicy())->assertCanDelete(true, [$role], false, RequestStatus::Completed, 'protocol', true, true);
                self::assertContains($role, [Role::IcManager, Role::Expert]);
            } catch (OpinionDenied $error) {
                self::assertNotContains($role, [Role::IcManager, Role::Expert]);
                self::assertSame('DOC-014', $error->ruleId);
            }
        }
    }

    public function testRejectsInactiveArchivedWrongStageAndMissingDocuments(): void
    {
        $cases = [
            [false, false, RequestStatus::Completed, 'protocol', true, true],
            [true, true, RequestStatus::Completed, 'protocol', true, true],
            [true, false, RequestStatus::InProgress, 'protocol', true, true],
            [true, false, RequestStatus::Withdrawn, 'protocol', true, true],
            [true, false, RequestStatus::Completed, 'act', true, true],
            [true, false, RequestStatus::Completed, 'protocol', false, true],
            [true, false, RequestStatus::Completed, 'protocol', true, false],
        ];
        foreach ($cases as [$active, $archived, $status, $route, $report, $opinion]) {
            try {
                (new OpinionDeletionPolicy())->assertCanDelete($active, [Role::Expert], $archived, $status, $route, $report, $opinion);
                self::fail('Deletion must be denied');
            } catch (OpinionDenied $error) {
                self::assertSame('DOC-014', $error->ruleId);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\Role;
use App\Domain\Request\SecurityDecisionCorrectionPolicy;
use App\Domain\Request\SecurityDecisionDenied;
use PHPUnit\Framework\TestCase;

final class SecurityDecisionCorrectionPolicyTest extends TestCase
{
    public function testOnlyActiveAdministratorCanChangeExistingDecisionOutsideArchive(): void
    {
        $policy = new SecurityDecisionCorrectionPolicy();
        foreach (['approve' => 'decline', 'decline' => 'approve'] as $old => $new) {
            $policy->assertAllowed(true, [Role::Administrator], false, $old, $new);
            $this->addToAssertionCount(1);
        }
        foreach (
            [
            ...array_map(static fn (Role $role): array => [true, [$role], false, 'approve', 'decline'], array_filter(Role::cases(), static fn (Role $role): bool => $role !== Role::Administrator)),
            [false, [Role::Administrator], false, 'approve', 'decline'],
            [true, [Role::Administrator], true, 'approve', 'decline'],
            [true, [Role::Administrator], false, null, 'decline'],
            [true, [Role::Administrator], false, 'approve', 'approve'],
            [true, [Role::Administrator], false, 'approve', 'return'],
            ] as $arguments
        ) {
            try {
                $policy->assertAllowed(...$arguments);
                self::fail('SEC-006 must reject this correction');
            } catch (SecurityDecisionDenied $error) {
                self::assertSame('SEC-006', $error->ruleId);
            }
        }
    }
}

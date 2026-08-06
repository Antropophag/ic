<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Request;

use App\Domain\Request\ReportDeletionDenied;
use App\Domain\Request\ReportDeletionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportDeletionPolicyTest extends TestCase
{
    public function testAssignedExecutorCanDeleteActiveReport(): void
    {
        (new ReportDeletionPolicy())->assertCanDelete(true, false, true);
        self::addToAssertionCount(1);
    }

    public function testManagerCanDeleteActiveReport(): void
    {
        (new ReportDeletionPolicy())->assertCanDelete(false, true, true);
        self::addToAssertionCount(1);
    }

    #[DataProvider('deniedCases')]
    public function testDeniedCases(bool $isExecutor, bool $isManager, bool $hasActiveReport): void
    {
        $this->expectException(ReportDeletionDenied::class);
        $this->expectExceptionMessage('DOC-011');
        (new ReportDeletionPolicy())->assertCanDelete($isExecutor, $isManager, $hasActiveReport);
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function deniedCases(): iterable
    {
        yield 'посторонний сотрудник' => [false, false, true];
        yield 'нет отчёта для удаления' => [true, false, false];
        yield 'нет отчёта даже для руководителя' => [false, true, false];
    }
}

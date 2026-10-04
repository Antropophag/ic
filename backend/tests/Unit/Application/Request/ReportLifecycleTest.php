<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\DeleteReportCommand;
use App\Application\Request\Command\UploadReportCommand;
use App\Application\Request\ReportLifecycleSnapshot;
use App\Application\Request\UseCase\ReportLifecycle;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\ReportDenied;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\TestCase;

final class ReportLifecycleTest extends TestCase
{
    public function testUploadValidatesAndHashesBeforeOpeningTransaction(): void
    {
        $gateway = new InMemoryReportLifecycleGateway($this->snapshot());
        $command = new UploadReportCommand(41, 7, 'report.pdf', 'application/pdf', 12, '/tmp/report.pdf');

        $result = (new ReportLifecycle($gateway))->upload($command);

        self::assertSame(1, $gateway->hashCount);
        self::assertSame(1, $gateway->transactionCount);
        self::assertSame($command, $gateway->uploaded);
        self::assertSame('opinion_preparation', $result->toArray()['status']);
    }

    public function testUnauthorizedUploadIsRejectedInsideTransactionBeforePersistence(): void
    {
        $gateway = new InMemoryReportLifecycleGateway($this->snapshot(isExecutor: false));

        try {
            (new ReportLifecycle($gateway))->upload(
                new UploadReportCommand(41, 8, 'report.pdf', 'application/pdf', 12, '/tmp/report.pdf'),
            );
            self::fail('Expected report upload denial.');
        } catch (ReportDenied) {
            self::assertSame(1, $gateway->hashCount);
            self::assertSame(1, $gateway->transactionCount);
            self::assertNull($gateway->uploaded);
        }
    }

    public function testDeleteChecksActiveReportThenRejectsStaleLockVersionBeforePersistence(): void
    {
        $gateway = new InMemoryReportLifecycleGateway($this->snapshot(lockVersion: 4));

        try {
            (new ReportLifecycle($gateway))->delete(new DeleteReportCommand(41, 3, 7, 'Устаревшая версия'));
            self::fail('Expected concurrent modification.');
        } catch (ConcurrentRequestModification) {
            self::assertSame(1, $gateway->transactionCount);
            self::assertSame(1, $gateway->activeReportLookupCount);
            self::assertNull($gateway->deleted);
        }
    }

    private function snapshot(int $lockVersion = 3, bool $isExecutor = true): ReportLifecycleSnapshot
    {
        return new ReportLifecycleSnapshot(
            RequestStatus::InProgress,
            $lockVersion,
            $isExecutor,
            false,
            false,
        );
    }
}

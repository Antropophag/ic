<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\CreateRequestCommand;
use App\Application\Request\CreationContext;
use App\Application\Request\Port\RequestCreationGateway;
use App\Application\Request\UseCase\CreateRequest;
use App\Domain\Request\RequestCreationDenied;
use App\Domain\Request\Role;
use PHPUnit\Framework\TestCase;

final class CreateRequestTest extends TestCase
{
    public function testDeniedRoleIsRejectedBeforeDepartmentLockAndNumberAllocation(): void
    {
        $gateway = $this->createMock(RequestCreationGateway::class);
        $gateway->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $gateway->expects(self::once())->method('creationContext')
            ->willReturn(new CreationContext([Role::IcManager], true));
        $gateway->expects(self::never())->method('departmentSnapshotForUpdate');
        $gateway->expects(self::never())->method('allocateNumber');

        $this->expectException(RequestCreationDenied::class);
        (new CreateRequest($gateway))->execute($this->command());
    }

    public function testRejectedAuditIsDelegatedWithoutStartingCreationTransaction(): void
    {
        $gateway = $this->createMock(RequestCreationGateway::class);
        $gateway->expects(self::never())->method('transactional');
        $gateway->expects(self::once())->method('recordRejectedCreation')->with(42, 'REQ-001');

        (new CreateRequest($gateway))->recordRejected($this->command(), 'REQ-001');
    }

    private function command(): CreateRequestCommand
    {
        return new CreateRequestCommand(42, 'Образец', 'Завод', 'Поставщик', 1, 'Методика');
    }
}

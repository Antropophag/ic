<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Request;

use App\Application\Request\Command\ChangeRequestDepartmentCommand;
use App\Application\Request\DepartmentChangeSnapshot;
use App\Application\Request\UseCase\ChangeRequestDepartment;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestDepartmentChangeDenied;
use App\Domain\Request\RequestNotFound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChangeRequestDepartmentTest extends TestCase
{
    public function testAdministratorChangesDepartmentAndRecordsSuccessfulAudit(): void
    {
        $snapshot = new DepartmentChangeSnapshot('Старое', 'bitrix:42', 4, true);
        $gateway = new InMemoryRequestDepartmentGateway(true, $snapshot);

        $result = (new ChangeRequestDepartment($gateway))->execute($this->command())->toArray();

        self::assertSame(['id' => 17, 'department' => 'Новое', 'lock_version' => 5], $result);
        self::assertSame('Новое', $gateway->persistedDepartment);
        self::assertSame(5, $gateway->persistedLockVersion);
        self::assertSame([
            'requestId' => 17,
            'actorId' => 23,
            'previous' => $snapshot,
            'department' => 'Новое',
            'ruleId' => 'REQ-011',
        ], $gateway->audit);
    }

    #[DataProvider('deniedActors')]
    public function testUnauthorizedActorIsDeniedWithoutMutation(bool $administrator, bool $active): void
    {
        $gateway = new InMemoryRequestDepartmentGateway(
            $administrator,
            new DepartmentChangeSnapshot('Старое', null, 4, $active),
        );

        try {
            (new ChangeRequestDepartment($gateway))->execute($this->command());
            self::fail('Expected department change denial.');
        } catch (RequestDepartmentChangeDenied $error) {
            self::assertSame(
                'Изменять подразделение заявки может только активный администратор.',
                $error->getMessage(),
            );
        }

        $this->assertUnchanged($gateway);
    }

    public function testMissingRequestIsPreserved(): void
    {
        $gateway = new InMemoryRequestDepartmentGateway(true, null);

        $this->expectException(RequestNotFound::class);
        (new ChangeRequestDepartment($gateway))->execute($this->command());
    }

    public function testStaleLockVersionPreservesState(): void
    {
        $gateway = new InMemoryRequestDepartmentGateway(
            true,
            new DepartmentChangeSnapshot('Старое', null, 5, true),
        );

        try {
            (new ChangeRequestDepartment($gateway))->execute($this->command());
            self::fail('Expected optimistic-lock conflict.');
        } catch (ConcurrentRequestModification) {
            $this->assertUnchanged($gateway);
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function deniedActors(): iterable
    {
        yield 'active non-administrator' => [false, true];
        yield 'inactive administrator' => [true, false];
    }

    private function command(): ChangeRequestDepartmentCommand
    {
        return new ChangeRequestDepartmentCommand(17, 'Новое', 4, 23);
    }

    private function assertUnchanged(InMemoryRequestDepartmentGateway $gateway): void
    {
        self::assertNull($gateway->persistedDepartment);
        self::assertNull($gateway->persistedLockVersion);
        self::assertNull($gateway->audit);
    }
}

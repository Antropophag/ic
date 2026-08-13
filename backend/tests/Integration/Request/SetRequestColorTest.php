<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\Command\SetRequestColorCommand;
use App\Application\Request\CreateRequestInput;
use App\Application\Request\UseCase\SetRequestColor;
use App\Domain\Request\ColorMarkDenied;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RequestColor;
use App\Domain\Request\RequestNotFound;
use App\Infrastructure\Request\RequestRepository;
use Tests\Integration\IntegrationTestCase;

final class SetRequestColorTest extends IntegrationTestCase
{
    public function testManagerSetsColorAndWritesAudit(): void
    {
        [$requestId, $lockVersion, $managerId] = $this->fixture('success');

        $result = $this->useCase()->execute(
            new SetRequestColorCommand($requestId, RequestColor::Blue, $lockVersion, $managerId),
        );

        self::assertSame($requestId, $result->requestId);
        self::assertSame(RequestColor::Blue, $result->color);
        self::assertSame($lockVersion + 1, $result->lockVersion);
        self::assertSame(['color' => 'blue', 'lock_version' => $lockVersion + 1], $this->db()->createCommand(
            'SELECT color, lock_version FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryOne());
        $audit = $this->db()->createCommand(
            "SELECT event_type, rule_id, payload_json FROM {{%audit_events}} "
            . "WHERE entity_id = :id AND event_type = 'request.color_marked'",
            [':id' => $requestId],
        )->queryOne();
        self::assertSame('request.color_marked', $audit['event_type']);
        self::assertSame('WF-009', $audit['rule_id']);
        self::assertSame(['color' => 'blue'], json_decode($audit['payload_json'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testInactiveManagerIsDeniedWithoutMutation(): void
    {
        [$requestId, $lockVersion] = $this->fixture('inactive');
        $managerId = $this->createUser('color.inactive.disabled', 'Неактивный руководитель', null, false);
        $this->grantRole($managerId, 'ic_manager');

        $this->assertDeniedWithoutMutation($requestId, $lockVersion, $managerId, 'AUTH-003');
    }

    public function testOrdinaryActorIsDeniedWithoutMutation(): void
    {
        [$requestId, $lockVersion] = $this->fixture('ordinary');
        $actorId = $this->createUser('color.ordinary.actor', 'Обычный сотрудник');

        $this->assertDeniedWithoutMutation($requestId, $lockVersion, $actorId, 'WF-009');
    }

    public function testStaleVersionPreservesColorAndVersion(): void
    {
        [$requestId, $lockVersion, $managerId] = $this->fixture('stale');

        try {
            $this->useCase()->execute(
                new SetRequestColorCommand($requestId, RequestColor::Green, $lockVersion + 1, $managerId),
            );
            self::fail('Expected optimistic-lock conflict.');
        } catch (ConcurrentRequestModification $error) {
            self::assertSame('WF-003', $error->ruleId);
        }

        $this->assertRequestUnchanged($requestId, $lockVersion);
    }

    public function testMissingRequestIsPreserved(): void
    {
        $managerId = $this->createUser('color.missing.manager', 'Руководитель');
        $this->grantRole($managerId, 'ic_manager');

        $this->expectException(RequestNotFound::class);
        $this->useCase()->execute(new SetRequestColorCommand(PHP_INT_MAX, RequestColor::Red, 1, $managerId));
    }

    /** @return array{int, int, int} */
    private function fixture(string $marker): array
    {
        $initiatorId = $this->createUser("color.{$marker}.initiator", 'Инициатор');
        $managerId = $this->createUser("color.{$marker}.manager", 'Руководитель');
        $this->grantRole($managerId, 'laboratory_manager');
        $input = new CreateRequestInput();
        $input->setAttributes([
            'productName' => "Цвет {$marker}",
            'manufacturer' => 'Завод',
            'supplier' => 'Поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Методика',
        ]);
        $request = (new RequestRepository($this->db()))->create($input, $initiatorId);
        return [(int) $request['id'], (int) $request['lock_version'], $managerId];
    }

    private function useCase(): SetRequestColor
    {
        return new SetRequestColor(new RequestRepository($this->db()));
    }

    private function assertDeniedWithoutMutation(int $requestId, int $lockVersion, int $actorId, string $ruleId): void
    {
        try {
            $this->useCase()->execute(new SetRequestColorCommand($requestId, RequestColor::Orange, $lockVersion, $actorId));
            self::fail('Expected color mark denial.');
        } catch (ColorMarkDenied $error) {
            self::assertSame($ruleId, $error->ruleId);
        }
        $this->assertRequestUnchanged($requestId, $lockVersion);
    }

    private function assertRequestUnchanged(int $requestId, int $lockVersion): void
    {
        self::assertSame(['color' => 'white', 'lock_version' => $lockVersion], $this->db()->createCommand(
            'SELECT color, lock_version FROM {{%requests}} WHERE id = :id',
            [':id' => $requestId],
        )->queryOne());
        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.color_marked'",
            [':id' => $requestId],
        ));
    }
}

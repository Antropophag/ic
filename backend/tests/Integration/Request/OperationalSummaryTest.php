<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Http\Request\CreateRequest as CreateRequestInput;
use App\Infrastructure\Clock;
use App\Infrastructure\Request\OperationalSummaryQuery;
use App\Infrastructure\Request\RequestDashboardQuery;
use Tests\Integration\IntegrationTestCase;

final class OperationalSummaryTest extends IntegrationTestCase
{
    public function testOperationalStatusesAndAssignmentsFormExactTotals(): void
    {
        $manager = $this->roleUser('summary.manager', 'Руководитель', 'ic_manager');
        $initiator = $this->createUser('summary.initiator', 'Инициатор');
        $executor = $this->roleUser('summary.executor', 'Кашин', 'ic_executor');

        $unassigned = $this->request($initiator, 'без исполнителя');
        $ready = $this->request($initiator, 'к запуску');
        $progress = $this->request($initiator, 'в работе');
        $suspended = $this->request($initiator, 'приостановлена');
        $opinion = $this->request($initiator, 'экспертиза');
        $security = $this->request($initiator, 'сб');
        $completed = $this->request($initiator, 'завершена');

        foreach ([$ready, $progress, $suspended, $opinion, $security, $completed] as $request) {
            $this->assign((int) $request['id'], $executor, $manager);
        }
        $this->updateStatus((int) $progress['id'], 'in_progress');
        $this->updateStatus((int) $suspended['id'], 'suspended');
        $this->updateStatus((int) $opinion['id'], 'opinion_preparation');
        $this->updateStatus((int) $security['id'], 'security_review');
        $this->updateStatus((int) $completed['id'], 'completed');
        $this->updateColor((int) $ready['id'], 'blue');
        $this->updateColor((int) $progress['id'], 'orange');
        $this->updateColor((int) $suspended['id'], 'violet');

        $summary = $this->summary($manager);

        self::assertSame([
            'active' => 4,
            'unassigned' => 1,
            'ready_to_start' => 1,
            'in_progress' => 1,
            'suspended' => 1,
            'expertise' => 1,
            'security_review' => 1,
        ], array_diff_key($summary, ['directions' => true]));
        self::assertSame($summary['active'], $summary['unassigned'] + $summary['ready_to_start']
            + $summary['in_progress'] + $summary['suspended']);
        self::assertSame(
            ['metrology', 'mechanical', 'electrical', 'unclassified'],
            array_column($summary['directions'], 'id'),
        );
        self::assertSame([1, 1, 1, 1], array_column($summary['directions'], 'active'));
        self::assertSame([0, 0, 0, 1], array_column($summary['directions'], 'unassigned'));
        self::assertSame(1, $this->executor($summary, $executor, 'mechanical')['active']);
        self::assertSame(1, $this->executor($summary, $executor, 'metrology')['active']);
        self::assertSame(1, $this->executor($summary, $executor, 'electrical')['active']);
        self::assertSame([0, 0], array_values(array_intersect_key(
            $this->executor($summary, $executor, 'mechanical'),
            ['in_progress' => true, 'suspended' => true],
        )));
        self::assertSame([1, 0], array_values(array_intersect_key(
            $this->executor($summary, $executor, 'metrology'),
            ['in_progress' => true, 'suspended' => true],
        )));
        self::assertSame([0, 1], array_values(array_intersect_key(
            $this->executor($summary, $executor, 'electrical'),
            ['in_progress' => true, 'suspended' => true],
        )));
    }

    public function testLifecycleStatusesMoveBetweenOperationalCategories(): void
    {
        $manager = $this->roleUser('summary.lifecycle.manager', 'Руководитель', 'laboratory_manager');
        $initiator = $this->createUser('summary.lifecycle.initiator', 'Инициатор');
        $executor = $this->roleUser('summary.lifecycle.executor', 'Исполнитель', 'ic_executor');
        $request = $this->request($initiator, 'жизненный цикл');

        self::assertSame([1, 1, 0, 0, 0], $this->categoryValues($this->summary($manager)));
        $this->assign((int) $request['id'], $executor, $manager);
        self::assertSame([1, 0, 1, 0, 0], $this->categoryValues($this->summary($manager)));
        $this->updateStatus((int) $request['id'], 'in_progress');
        self::assertSame([1, 0, 0, 1, 0], $this->categoryValues($this->summary($manager)));
        $this->updateStatus((int) $request['id'], 'suspended');
        self::assertSame([1, 0, 0, 0, 1], $this->categoryValues($this->summary($manager)));
        $this->updateStatus((int) $request['id'], 'in_progress');
        self::assertSame([1, 0, 0, 1, 0], $this->categoryValues($this->summary($manager)));
        $this->updateStatus((int) $request['id'], 'opinion_preparation');
        $summary = $this->summary($manager);
        self::assertSame([0, 0, 0, 0, 0], $this->categoryValues($summary));
        self::assertSame(1, $summary['expertise']);
        self::assertSame(0, $summary['security_review']);
        $this->updateStatus((int) $request['id'], 'security_review');
        $summary = $this->summary($manager);
        self::assertSame(0, $summary['expertise']);
        self::assertSame(1, $summary['security_review']);
    }


    public function testReassignmentMovesOnlyCurrentOperationalWorkload(): void
    {
        $manager = $this->roleUser('summary.reassign.manager', 'Руководитель', 'ic_manager');
        $initiator = $this->createUser('summary.reassign.initiator', 'Инициатор');
        $first = $this->roleUser('summary.reassign.first', 'Первый', 'ic_executor');
        $second = $this->roleUser('summary.reassign.second', 'Второй', 'ic_executor');
        $request = $this->request($initiator, 'переназначение');
        $assignmentId = $this->assign((int) $request['id'], $first, $manager);

        self::assertSame(1, $this->executor($this->summary($manager), $first, 'unclassified')['active']);
        $this->db()->createCommand()->update(
            '{{%request_assignments}}',
            ['valid_to' => Clock::now()],
            ['id' => $assignmentId],
        )->execute();
        $this->assign((int) $request['id'], $second, $manager);

        $summary = $this->summary($manager);
        self::assertNull($this->findExecutor($summary, $first, 'unclassified'));
        self::assertSame(1, $this->executor($summary, $second, 'unclassified')['active']);
    }

    public function testUnavailableAssigneeIsVisibleWithoutChangingTotals(): void
    {
        $manager = $this->roleUser('summary.legacy.manager', 'Руководитель', 'ic_manager');
        $initiator = $this->createUser('summary.legacy.initiator', 'Инициатор');
        $inactive = $this->roleUser('summary.legacy.executor', 'Бывший исполнитель', 'ic_executor');
        $request = $this->request($initiator, 'legacy назначение');
        $this->assign((int) $request['id'], $inactive, $manager);
        $this->db()->createCommand()->update('{{%users}}', ['is_active' => 0], ['id' => $inactive])->execute();

        $summary = $this->summary($manager);

        self::assertSame([1, 0, 1, 0, 0], $this->categoryValues($summary));
        self::assertSame([
            'user_id' => null,
            'display_name' => 'Недоступный исполнитель',
            'is_available' => false,
            'active' => 1,
            'in_progress' => 0,
            'suspended' => 0,
        ], $this->unavailableExecutor($summary));
    }

    public function testAssigneeWithoutExecutorRoleIsGroupedAsUnavailable(): void
    {
        $manager = $this->roleUser('summary.revoked.manager', 'Руководитель', 'ic_manager');
        $initiator = $this->createUser('summary.revoked.initiator', 'Инициатор');
        $assignee = $this->roleUser('summary.revoked.executor', 'Бывший по роли', 'ic_executor');
        $request = $this->request($initiator, 'назначение с отозванной ролью');
        $this->assign((int) $request['id'], $assignee, $manager);
        $this->db()->createCommand(
            "DELETE user_role FROM {{%user_roles}} user_role JOIN {{%roles}} role ON role.id = user_role.role_id "
            . "WHERE user_role.user_id = :assignee AND role.code = 'ic_executor'",
            [':assignee' => $assignee],
        )->execute();

        $summary = $this->summary($manager);

        self::assertSame([1, 0, 1, 0, 0], $this->categoryValues($summary));
        self::assertSame(1, $this->unavailableExecutor($summary)['active']);
        self::assertNull($this->findExecutor($summary, $assignee, 'unclassified'));
    }

    public function testExecutorCountsAreMergedAcrossUnclassifiedColors(): void
    {
        $manager = $this->roleUser('summary.colors.manager', 'Руководитель', 'ic_manager');
        $initiator = $this->createUser('summary.colors.initiator', 'Инициатор');
        $executor = $this->roleUser('summary.colors.executor', 'Исполнитель', 'ic_executor');
        $red = $this->request($initiator, 'красная метка');
        $green = $this->request($initiator, 'зелёная метка');
        $this->assign((int) $red['id'], $executor, $manager);
        $this->assign((int) $green['id'], $executor, $manager);
        $this->updateColor((int) $red['id'], 'red');
        $this->updateColor((int) $green['id'], 'green');

        $direction = $this->direction($this->summary($manager), 'unclassified');

        self::assertCount(1, $direction['executors']);
        self::assertSame(2, $direction['executors'][0]['active']);
    }

    public function testDifferentUnavailableExecutorsBecomeOneSyntheticUnclassifiedRow(): void
    {
        $manager = $this->roleUser('summary.synthetic.manager', 'Руководитель', 'ic_manager');
        $initiator = $this->createUser('summary.synthetic.initiator', 'Инициатор');
        $inactive = $this->roleUser('summary.synthetic.inactive', 'Недоступный Первый', 'ic_executor');
        $revoked = $this->roleUser('summary.synthetic.revoked', 'Недоступный Второй', 'ic_executor');
        $red = $this->request($initiator, 'недоступный красный');
        $legacy = $this->request($initiator, 'недоступный legacy');
        $this->assign((int) $red['id'], $inactive, $manager);
        $this->assign((int) $legacy['id'], $revoked, $manager);
        $this->updateColor((int) $red['id'], 'red');
        $this->db()->createCommand()->update('{{%users}}', ['is_active' => 0], ['id' => $inactive])->execute();
        $this->db()->createCommand(
            "DELETE user_role FROM {{%user_roles}} user_role JOIN {{%roles}} role ON role.id = user_role.role_id "
            . "WHERE user_role.user_id = :assignee AND role.code = 'ic_executor'",
            [':assignee' => $revoked],
        )->execute();

        $direction = $this->direction($this->summary($manager), 'unclassified');

        self::assertSame(2, $direction['active']);
        self::assertCount(1, $direction['executors']);
        self::assertSame([
            'user_id' => null,
            'display_name' => 'Недоступный исполнитель',
            'is_available' => false,
            'active' => 2,
            'in_progress' => 0,
            'suspended' => 0,
        ], $direction['executors'][0]);
    }

    public function testOperationalSummaryIsAvailableToEveryAuthenticatedRole(): void
    {
        $manager = $this->roleUser('summary.access.manager', 'Руководитель', 'ic_manager');
        $employee = $this->createUser('summary.employee', 'Сотрудник');
        $executor = $this->roleUser('summary.access.executor', 'Исполнитель', 'ic_executor');
        $request = $this->request($employee, 'ограниченная детализация');
        $this->assign((int) $request['id'], $executor, $manager);

        $managerSummary = $this->summary($manager);
        $employeeSummary = $this->summary($employee);
        $executorSummary = $this->summary($executor);

        self::assertSame(1, $managerSummary['active']);
        self::assertSame(1, $employeeSummary['active']);
        self::assertSame(1, $executorSummary['active']);
        self::assertNotEmpty($this->direction($managerSummary, 'unclassified')['executors']);
        self::assertSame(
            $this->direction($managerSummary, 'unclassified')['unassigned'],
            $this->direction($employeeSummary, 'unclassified')['unassigned'],
        );
        foreach ([$employeeSummary, $executorSummary] as $summary) {
            foreach ($summary['directions'] as $direction) {
                self::assertSame([], $direction['executors']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function summary(int $actorId): array
    {
        return (new RequestDashboardQuery($this->db()))->findFor($actorId)['operational_summary'];
    }

    /**
     * @param array<string, mixed> $summary
     * @return list<int>
     */
    private function categoryValues(array $summary): array
    {
        return array_map(static fn (string $key): int => $summary[$key], [
            'active', 'unassigned', 'ready_to_start', 'in_progress', 'suspended',
        ]);
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function direction(array $summary, string $directionId): array
    {
        foreach ($summary['directions'] as $direction) {
            if ($direction['id'] === $directionId) {
                return $direction;
            }
        }
        self::fail("Direction {$directionId} is absent.");
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function executor(array $summary, int $userId, string $directionId): array
    {
        $executor = $this->findExecutor($summary, $userId, $directionId);
        if ($executor !== null) {
            return $executor;
        }
        self::fail("Executor {$userId} is absent.");
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>|null
     */
    private function findExecutor(array $summary, int $userId, string $directionId): ?array
    {
        foreach ($summary['directions'] as $direction) {
            if ($direction['id'] !== $directionId) {
                continue;
            }
            foreach ($direction['executors'] as $executor) {
                if ($executor['user_id'] === $userId) {
                    return $executor;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function unavailableExecutor(array $summary): array
    {
        foreach ($summary['directions'] as $direction) {
            foreach ($direction['executors'] as $executor) {
                if (!$executor['is_available']) {
                    return $executor;
                }
            }
        }
        self::fail('Unavailable executor workload is absent.');
    }

    private function roleUser(string $login, string $name, string $role): int
    {
        $id = $this->createUser($login, $name);
        $this->grantRole($id, $role);
        return $id;
    }

    /** @return array<string, mixed> */
    private function request(int $initiator, string $marker): array
    {
        $input = new CreateRequestInput([
            'productName' => "Сводка {$marker}",
            'manufacturer' => 'Тестовый завод',
            'supplier' => 'Тестовый поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Проверка operational summary',
        ]);
        return (new \App\Application\Request\UseCase\CreateRequest(
            new \App\Infrastructure\Persistence\Request\RequestCreationPersistenceAdapter($this->db()),
        ))->execute($input->toCommand($initiator))->toArray();
    }

    private function updateStatus(int $requestId, string $status): void
    {
        $this->db()->createCommand()->update('{{%requests}}', ['status' => $status], ['id' => $requestId])->execute();
    }

    private function updateColor(int $requestId, string $color): void
    {
        $this->db()->createCommand()->update('{{%requests}}', ['color' => $color], ['id' => $requestId])->execute();
    }

    private function assign(int $requestId, int $userId, int $authorId): int
    {
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'executor',
            'user_id' => $userId,
            'assigned_by' => $authorId,
            'valid_from' => Clock::now(),
        ])->execute();
        return (int) $this->db()->getLastInsertID();
    }
}

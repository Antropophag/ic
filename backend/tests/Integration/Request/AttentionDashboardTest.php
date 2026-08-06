<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\CreateRequestInput;
use App\Infrastructure\Clock;
use App\Infrastructure\Request\RequestQuery;
use App\Infrastructure\Request\RequestRepository;
use Tests\Integration\IntegrationTestCase;

final class AttentionDashboardTest extends IntegrationTestCase
{
    public function testManagerSeesOnlyRequestsThatNeedAnExecutor(): void
    {
        $manager = $this->roleUser('attention.manager', 'Руководитель', 'ic_manager');
        $initiator = $this->createUser('attention.initiator', 'Инициатор');
        $executor = $this->roleUser('attention.executor', 'Исполнитель', 'ic_executor');
        $unassigned = $this->request($initiator, 'без исполнителя');
        $assigned = $this->request($initiator, 'с исполнителем');
        $this->assign((int) $assigned['id'], 'executor', $executor, $manager);

        $query = new RequestQuery($this->db());
        self::assertSame(1, $this->queueCount($query->attentionDashboard($manager), 'assign_executor'));
        $page = $query->findPage($manager, 1, 1, 'all', null, '', 'desc', 'assign_executor');
        self::assertSame(1, $page['total']);
        self::assertSame((int) $unassigned['id'], (int) $page['items'][0]['id']);
        self::assertSame(1, $page['pageCount']);
    }

    public function testEmployeeCannotUseAQueueByForgingItsIdentifier(): void
    {
        $employee = $this->createUser('attention.employee', 'Сотрудник');
        $this->request($employee, 'обычная заявка');
        $query = new RequestQuery($this->db());

        self::assertSame([], $query->attentionDashboard($employee)['categories']);
        $this->grantRole($employee, 'administrator');
        self::assertSame([], $query->attentionDashboard($employee)['categories']);
        self::assertSame(0, $query->findPage(
            $employee,
            1,
            10,
            'all',
            null,
            '',
            'desc',
            'assign_executor',
        )['total']);
    }

    public function testExecutorQueuesRequireCurrentAssignmentAndActionableStatus(): void
    {
        $initiator = $this->createUser('attention.executor.initiator', 'Инициатор');
        $executor = $this->roleUser('attention.executor.actor', 'Исполнитель', 'ic_executor');
        $other = $this->roleUser('attention.executor.other', 'Другой исполнитель', 'ic_executor');
        $assigned = $this->request($initiator, 'назначенная');
        $foreign = $this->request($initiator, 'чужая');
        $report = $this->request($initiator, 'нужен отчёт');
        $opinionReport = $this->request($initiator, 'повторный отчёт на экспертизе');
        $this->assign((int) $assigned['id'], 'executor', $executor, $initiator);
        $this->assign((int) $foreign['id'], 'executor', $other, $initiator);
        $this->assign((int) $report['id'], 'executor', $executor, $initiator);
        $this->assign((int) $opinionReport['id'], 'executor', $executor, $initiator);
        $this->updateStatus((int) $report['id'], 'in_progress');
        $this->updateStatus((int) $opinionReport['id'], 'opinion_preparation');

        $dashboard = (new RequestQuery($this->db()))->attentionDashboard($executor);
        self::assertSame(1, $this->queueCount($dashboard, 'start_or_resume_work'));
        self::assertSame(2, $this->queueCount($dashboard, 'upload_report'));
    }

    public function testExpertAndSecurityQueuesFollowAssignmentAndStatus(): void
    {
        $initiator = $this->createUser('attention.process.initiator', 'Инициатор');
        $expert = $this->roleUser('attention.expert', 'Эксперт', 'expert');
        $security = $this->roleUser('attention.security', 'Сотрудник СБ', 'security_officer');
        $claim = $this->request($initiator, 'взять экспертизу');
        $publish = $this->request($initiator, 'готовить заключение');
        $review = $this->request($initiator, 'контроль СБ');
        $this->updateStatus((int) $claim['id'], 'opinion_preparation');
        $this->updateStatus((int) $publish['id'], 'opinion_preparation');
        $this->assign((int) $publish['id'], 'expert', $expert, $expert);
        $this->updateStatus((int) $review['id'], 'security_review');

        $expertDashboard = (new RequestQuery($this->db()))->attentionDashboard($expert);
        self::assertSame(1, $this->queueCount($expertDashboard, 'claim_expert'));
        self::assertSame(1, $this->queueCount($expertDashboard, 'publish_opinion'));
        self::assertSame(1, $this->queueCount(
            (new RequestQuery($this->db()))->attentionDashboard($security),
            'security_decision',
        ));

        $this->db()->createCommand(
            "DELETE ur FROM {{%user_roles}} ur JOIN {{%roles}} role ON role.id = ur.role_id "
            . "WHERE ur.user_id = :expert AND role.code = 'expert'",
            [':expert' => $expert],
        )->execute();
        $afterRevocation = (new RequestQuery($this->db()))->attentionDashboard($expert);
        self::assertSame(1, $this->queueCount($afterRevocation, 'publish_opinion'));
        self::assertCount(1, $afterRevocation['categories']);
    }

    public function testMultipleRolesAreUnitedAndInactiveActorsGetNoCounts(): void
    {
        $initiator = $this->createUser('attention.multi.initiator', 'Инициатор');
        $actor = $this->roleUser('attention.multi', 'Совмещающий роли', 'ic_manager');
        $this->grantRole($actor, 'security_officer');
        $this->request($initiator, 'назначение');
        $securityRequest = $this->request($initiator, 'безопасность');
        $this->updateStatus((int) $securityRequest['id'], 'security_review');

        $dashboard = (new RequestQuery($this->db()))->attentionDashboard($actor);
        self::assertSame(1, $this->queueCount($dashboard, 'assign_executor'));
        self::assertSame(1, $this->queueCount($dashboard, 'security_decision'));

        $this->db()->createCommand()->update('{{%users}}', ['is_active' => 0], ['id' => $actor])->execute();
        self::assertSame([], (new RequestQuery($this->db()))->attentionDashboard($actor)['categories']);
    }

    public function testAssignmentMovesRequestBetweenQueuesAndCountIgnoresPagination(): void
    {
        $manager = $this->roleUser('attention.move.manager', 'Руководитель', 'laboratory_manager');
        $initiator = $this->createUser('attention.move.initiator', 'Инициатор');
        $executor = $this->roleUser('attention.move.executor', 'Исполнитель', 'ic_executor');
        $request = $this->request($initiator, 'перемещение');
        $query = new RequestQuery($this->db());
        self::assertSame(1, $this->queueCount($query->attentionDashboard($manager), 'assign_executor'));

        (new RequestRepository($this->db()))->assignExecutor(
            (int) $request['id'],
            $executor,
            (int) $request['lock_version'],
            $manager,
        );

        $this->assertQueueAbsent($query->attentionDashboard($manager), 'assign_executor');
        self::assertSame(1, $this->queueCount($query->attentionDashboard($executor), 'start_or_resume_work'));
        self::assertSame(1, $query->findPage(
            $executor,
            1,
            1,
            'all',
            null,
            '',
            'desc',
            'start_or_resume_work',
        )['total']);
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
            'productName' => "Тест {$marker}",
            'manufacturer' => 'Тестовый завод',
            'supplier' => 'Тестовый поставщик',
            'sampleQuantity' => 1,
            'testMethod' => 'Интеграционная проверка очередей',
        ]);
        return (new RequestRepository($this->db()))->create($input, $initiator);
    }

    private function updateStatus(int $requestId, string $status): void
    {
        $this->db()->createCommand()->update('{{%requests}}', ['status' => $status], ['id' => $requestId])->execute();
    }

    private function assign(int $requestId, string $type, int $userId, int $authorId): void
    {
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => $type,
            'user_id' => $userId,
            'assigned_by' => $authorId,
            'valid_from' => Clock::now(),
        ])->execute();
    }

    /** @param array{categories: list<array{id: string, title: string, description: string, count: int}>} $dashboard */
    private function queueCount(array $dashboard, string $id): int
    {
        foreach ($dashboard['categories'] as $category) {
            if ($category['id'] === $id) {
                return $category['count'];
            }
        }
        self::fail("Queue {$id} is not visible.");
    }

    /** @param array{categories: list<array{id: string, title: string, description: string, count: int}>} $dashboard */
    private function assertQueueAbsent(array $dashboard, string $id): void
    {
        self::assertNotContains($id, array_column($dashboard['categories'], 'id'));
    }
}

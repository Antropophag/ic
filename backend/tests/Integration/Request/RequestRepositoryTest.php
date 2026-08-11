<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\AssignmentDenied;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RejectDenied;
use App\Domain\Request\RequestNotFound;
use App\Domain\Request\RequestDepartmentChangeDenied;
use App\Domain\Request\RequestDepartmentMissing;
use App\Domain\Request\SuspendResumeDenied;
use App\Domain\Request\WithdrawDenied;
use App\Infrastructure\Clock;
use App\Infrastructure\Request\RequestRepository;
use App\Infrastructure\Request\RequestQuery;
use Tests\Integration\IntegrationTestCase;

final class RequestRepositoryTest extends IntegrationTestCase
{
    /** @return array<string, mixed> */
    private function createRegisteredRequest(int $initiatorId, string $marker): array
    {
        $input = new CreateRequestInput();
        $input->productName = "Тестовое изделие {$marker}";
        $input->manufacturer = 'Тестовый завод';
        $input->supplier = 'Тестовый поставщик';
        $input->sampleQuantity = 1;
        $input->testMethod = 'Интеграционный тест';

        return (new RequestRepository($this->db()))->create($input, $initiatorId);
    }

    public function testDepartmentIsSnapshottedAndDoesNotFollowProfileChanges(): void
    {
        $initiator = $this->createUser('dev.it.department.snapshot', 'Инициатор', null, true, 'Подразделение A');
        $first = $this->createRegisteredRequest($initiator, 'department-a');
        self::assertSame('Подразделение A', $first['department']);

        $this->db()->createCommand()->update('{{%users}}', ['department' => 'Подразделение B'], ['id' => $initiator])->execute();
        $firstDetails = (new RequestQuery($this->db()))->findDetails((int) $first['id'], $initiator);
        self::assertSame('Подразделение A', $firstDetails['item']['department']);

        $second = $this->createRegisteredRequest($initiator, 'department-b');
        self::assertSame('Подразделение B', $second['department']);
    }

    public function testCreationRequiresDepartment(): void
    {
        $initiator = $this->createUser('dev.it.department.missing', 'Без подразделения', null, true, null);
        $this->expectException(RequestDepartmentMissing::class);
        $this->createRegisteredRequest($initiator, 'department-missing');
    }

    public function testAdministratorChangesOnlyRequestSnapshotAndWritesAudit(): void
    {
        $initiator = $this->createUser('dev.it.department.owner', 'Инициатор', null, true, 'Подразделение A');
        $administrator = $this->createUser('dev.it.department.admin', 'Администратор');
        $this->grantRole($administrator, 'administrator');
        $request = $this->createRegisteredRequest($initiator, 'department-manual');
        $this->db()->createCommand()->update('{{%requests}}', ['department_external_id' => 'bitrix:42'], ['id' => $request['id']])->execute();

        $result = (new RequestRepository($this->db()))->changeDepartment(
            (int) $request['id'],
            'Подразделение C',
            (int) $request['lock_version'],
            $administrator,
        );

        self::assertSame('Подразделение C', $result['department']);
        $snapshot = $this->db()->createCommand(
            'SELECT department_name, department_external_id, department_source FROM {{%requests}} WHERE id = :id',
            [':id' => $request['id']],
        )->queryOne();
        self::assertSame(['department_name' => 'Подразделение C', 'department_external_id' => null, 'department_source' => 'manual'], $snapshot);
        self::assertSame('Подразделение A', $this->scalar('SELECT department FROM {{%users}} WHERE id = :id', [':id' => $initiator]));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.department_changed' AND rule_id = 'REQ-011'",
            [':id' => $request['id']],
        ));
    }

    public function testOrdinaryUserCannotChangeDepartment(): void
    {
        $initiator = $this->createUser('dev.it.department.denied', 'Инициатор');
        $request = $this->createRegisteredRequest($initiator, 'department-denied');
        $this->expectException(RequestDepartmentChangeDenied::class);
        (new RequestRepository($this->db()))->changeDepartment(
            (int) $request['id'],
            'Другое подразделение',
            (int) $request['lock_version'],
            $initiator,
        );
    }

    public function testDepartmentChangeConflictPreservesSnapshotAndDoesNotWriteAudit(): void
    {
        $initiator = $this->createUser('dev.it.department.conflict.owner', 'Инициатор');
        $administrator = $this->createUser('dev.it.department.conflict.admin', 'Администратор');
        $this->grantRole($administrator, 'administrator');
        $request = $this->createRegisteredRequest($initiator, 'department-conflict');
        $this->db()->createCommand()->update('{{%requests}}', [
            'department_external_id' => 'bitrix:conflict',
            'department_source' => 'bitrix24',
        ], ['id' => $request['id']])->execute();

        try {
            (new RequestRepository($this->db()))->changeDepartment(
                (int) $request['id'],
                'Новое подразделение',
                (int) $request['lock_version'] + 1,
                $administrator,
            );
            self::fail('Expected stale department change to be rejected.');
        } catch (ConcurrentRequestModification) {
            // Expected optimistic-lock conflict.
        }

        $snapshot = $this->db()->createCommand(
            'SELECT department_name, department_external_id, department_source FROM {{%requests}} WHERE id = :id',
            [':id' => $request['id']],
        )->queryOne();
        self::assertSame([
            'department_name' => 'Тестовое подразделение',
            'department_external_id' => 'bitrix:conflict',
            'department_source' => 'bitrix24',
        ], $snapshot);
        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE entity_id = :id AND event_type = 'request.department_changed'",
            [':id' => $request['id']],
        ));
    }

    public function testRejectFailsOnStaleLockVersion(): void
    {
        $manager = $this->createUser('dev.it.manager1', 'Тестовый руководитель 1');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator1', 'Тестовый инициатор 1');
        $request = $this->createRegisteredRequest($initiator, 'stale-reject');

        $repository = new RequestRepository($this->db());
        $this->expectException(ConcurrentRequestModification::class);
        $repository->rejectRequest((int) $request['id'], (int) $request['lock_version'] + 1, $manager, 'Не соответствует требованиям');
    }

    public function testRejectByManagerTransitionsStatusAndWritesAudit(): void
    {
        $manager = $this->createUser('dev.it.manager2', 'Тестовый руководитель 2');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator2', 'Тестовый инициатор 2');
        $request = $this->createRegisteredRequest($initiator, 'reject-audit');

        $repository = new RequestRepository($this->db());
        $result = $repository->rejectRequest((int) $request['id'], (int) $request['lock_version'], $manager, 'Не соответствует требованиям');

        self::assertSame('rejected', $result['status']);
        self::assertSame((int) $request['lock_version'] + 1, $result['lockVersion']);

        $auditCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE event_type = 'request.rejected' AND entity_id = :id",
            [':id' => $request['id']],
        );
        self::assertSame(1, (int) $auditCount);
        $savedReason = $this->scalar(
            "SELECT reason FROM {{%request_transitions}} WHERE request_id = :id AND action = 'reject'",
            [':id' => $request['id']],
        );
        self::assertSame('Не соответствует требованиям', $savedReason);
    }

    public function testOnlyManagerCanReject(): void
    {
        $employee = $this->createUser('dev.it.employee1', 'Обычный сотрудник');
        $initiator = $this->createUser('dev.it.initiator3', 'Тестовый инициатор 3');
        $request = $this->createRegisteredRequest($initiator, 'reject-denied');

        $repository = new RequestRepository($this->db());
        $this->expectException(RejectDenied::class);
        $repository->rejectRequest((int) $request['id'], (int) $request['lock_version'], $employee, 'Не соответствует требованиям');
    }

    public function testOnlyInitiatorCanWithdraw(): void
    {
        $other = $this->createUser('dev.it.other1', 'Другой сотрудник');
        $initiator = $this->createUser('dev.it.initiator4', 'Тестовый инициатор 4');
        $request = $this->createRegisteredRequest($initiator, 'withdraw-denied');

        $repository = new RequestRepository($this->db());
        $this->expectException(WithdrawDenied::class);
        $repository->withdrawRequest((int) $request['id'], (int) $request['lock_version'], $other, 'Заявка больше не актуальна');
    }

    public function testWithdrawIsBlockedAfterSecurityReview(): void
    {
        $initiator = $this->createUser('dev.it.initiator5', 'Тестовый инициатор 5');
        $expert = $this->createUser('dev.it.expert1', 'Тестовый эксперт');
        $request = $this->createRegisteredRequest($initiator, 'withdraw-after-sb');
        $requestId = (int) $request['id'];
        $now = Clock::now();

        // Имитируем прохождение контроля СБ напрямую в БД — минимальная
        // цепочка FK (документ → версия → заключение → security_checks),
        // без прогона полного HTTP-цикла отчёт/заключение.
        $this->db()->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId,
            'title' => 'Экспертное заключение',
            'created_by' => $expert,
            'created_at' => $now,
        ])->execute();
        $documentId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $documentId,
            'version' => 1,
            'storage_key' => str_repeat('a', 64),
            'original_name' => 'opinion.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'sha256' => str_repeat('0', 64),
            'uploaded_by' => $expert,
            'created_at' => $now,
        ])->execute();
        $versionId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%expert_opinions}}', [
            'request_id' => $requestId,
            'revision' => 1,
            'expert_id' => $expert,
            'body' => 'Заключение соответствует требованиям.',
            'document_version_id' => $versionId,
            'created_at' => $now,
        ])->execute();
        $opinionId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%security_checks}}', [
            'request_id' => $requestId,
            'expert_opinion_id' => $opinionId,
            'officer_id' => $expert,
            'decision' => 'return',
            'created_at' => $now,
        ])->execute();

        $repository = new RequestRepository($this->db());
        $this->expectException(ConcurrentRequestModification::class);
        $repository->withdrawRequest($requestId, (int) $request['lock_version'], $initiator, 'Заявка больше не актуальна');
    }

    public function testSuspendAndResumeTransitionStatus(): void
    {
        $manager = $this->createUser('dev.it.manager-suspend', 'Руководитель приостановки');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator-suspend', 'Инициатор приостановки');
        $executor = $this->createUser('dev.it.executor-suspend', 'Исполнитель приостановки');
        $this->grantRole($executor, 'ic_executor');
        $request = $this->createRegisteredRequest($initiator, 'suspend-resume');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $repository->assignExecutor($requestId, $executor, (int) $request['lock_version'], $manager);
        $started = $repository->startRequest($requestId, (int) $request['lock_version'] + 1, $manager);
        self::assertSame('in_progress', $started['status']);

        $suspended = $repository->suspendRequest($requestId, $started['lockVersion'], $executor, 'Ожидание оборудования');
        self::assertSame('suspended', $suspended['status']);
        $savedReason = $this->scalar(
            "SELECT reason FROM {{%request_transitions}} WHERE request_id = :id AND action = 'suspend'",
            [':id' => $requestId],
        );
        self::assertSame('Ожидание оборудования', $savedReason);

        $resumed = $repository->resumeRequest($requestId, $suspended['lockVersion'], $manager);
        self::assertSame('in_progress', $resumed['status']);

        $transitionCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%request_transitions}} WHERE request_id = :id AND rule_id = 'WF-005'",
            [':id' => $requestId],
        );
        self::assertSame(2, (int) $transitionCount);
    }

    public function testOnlyAssignedExecutorOrManagerCanSuspend(): void
    {
        $manager = $this->createUser('dev.it.manager-suspend2', 'Руководитель приостановки 2');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator-suspend2', 'Инициатор приостановки 2');
        $executor = $this->createUser('dev.it.executor-suspend2', 'Исполнитель приостановки 2');
        $this->grantRole($executor, 'ic_executor');
        $otherExecutor = $this->createUser('dev.it.executor-suspend3', 'Другой исполнитель приостановки');
        $this->grantRole($otherExecutor, 'ic_executor');
        $request = $this->createRegisteredRequest($initiator, 'suspend-denied');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $repository->assignExecutor($requestId, $executor, (int) $request['lock_version'], $manager);
        $started = $repository->startRequest($requestId, (int) $request['lock_version'] + 1, $manager);

        $this->expectException(SuspendResumeDenied::class);
        $repository->suspendRequest($requestId, $started['lockVersion'], $otherExecutor, 'Ожидание оборудования');
    }

    public function testSuspendFailsOnStaleLockVersion(): void
    {
        $manager = $this->createUser('dev.it.manager-suspend4', 'Руководитель приостановки 4');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator-suspend4', 'Инициатор приостановки 4');
        $request = $this->createRegisteredRequest($initiator, 'suspend-stale');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $started = $repository->startRequest($requestId, (int) $request['lock_version'], $manager);

        $this->expectException(ConcurrentRequestModification::class);
        $repository->suspendRequest($requestId, $started['lockVersion'] + 1, $manager, 'Ожидание оборудования');
    }

    public function testCanSuspendAndCanResumeFlagsInRegistry(): void
    {
        $manager = $this->createUser('dev.it.manager-suspend5', 'Руководитель приостановки 5');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator-suspend5', 'Инициатор приостановки 5');
        $outsider = $this->createUser('dev.it.outsider-suspend5', 'Посторонний приостановки');
        $request = $this->createRegisteredRequest($initiator, 'suspend-flags');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $started = $repository->startRequest($requestId, (int) $request['lock_version'], $manager);

        $managerRow = self::findRow($this->findAll($manager), $requestId);
        self::assertSame(1, (int) $managerRow['can_suspend']);
        self::assertSame(0, (int) $managerRow['can_resume']);

        $outsiderRow = self::findRow($this->findAll($outsider), $requestId);
        self::assertSame(0, (int) $outsiderRow['can_suspend']);

        $repository->suspendRequest($requestId, $started['lockVersion'], $manager, 'Ожидание оборудования');
        $managerRowAfter = self::findRow($this->findAll($manager), $requestId);
        self::assertSame(0, (int) $managerRowAfter['can_suspend']);
        self::assertSame(1, (int) $managerRowAfter['can_resume']);
    }

    public function testWithdrawSavesRequiredReason(): void
    {
        $initiator = $this->createUser('dev.it.initiator-withdrawreason', 'Инициатор причины отзыва');
        $request = $this->createRegisteredRequest($initiator, 'withdraw-reason');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $repository->withdrawRequest($requestId, (int) $request['lock_version'], $initiator, 'Больше не актуально');

        $savedReason = $this->scalar(
            "SELECT reason FROM {{%request_transitions}} WHERE request_id = :id AND action = 'withdraw'",
            [':id' => $requestId],
        );
        self::assertSame('Больше не актуально', $savedReason);
    }

    public function testCanRejectAndCanWithdrawFlagsInRegistry(): void
    {
        $manager = $this->createUser('dev.it.manager3', 'Тестовый руководитель 3');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator6', 'Тестовый инициатор 6');
        $request = $this->createRegisteredRequest($initiator, 'registry-flags');

        $managerPage = (new RequestQuery($this->db()))->findPage($manager, 1, 100, 'all', null, '', 'desc');
        $managerRow = self::findRow($managerPage['items'], (int) $request['id']);
        self::assertSame(1, (int) $managerRow['can_reject']);
        self::assertSame(0, (int) $managerRow['can_withdraw']);

        $initiatorPage = (new RequestQuery($this->db()))->findPage($initiator, 1, 100, 'all', null, '', 'desc');
        $initiatorRow = self::findRow($initiatorPage['items'], (int) $request['id']);
        self::assertSame(0, (int) $initiatorRow['can_reject']);
        self::assertSame(1, (int) $initiatorRow['can_withdraw']);
    }

    public function testRegistryIsFilteredAndPaginatedOnServer(): void
    {
        $initiator = $this->createUser('dev.it.registry-page', 'Инициатор пагинации');
        $other = $this->createUser('dev.it.registry-other', 'Инициатор пагинации');
        $first = $this->createRegisteredRequest($initiator, 'уникальный насос');
        $this->createRegisteredRequest($initiator, 'уникальный насос второй');
        $this->createRegisteredRequest($other, 'постороннее изделие');

        $page = (new RequestQuery($this->db()))->findPage(
            $initiator,
            1,
            1,
            'mine',
            'registered',
            'уникальный насос',
            'asc',
        );

        self::assertSame(2, $page['total']);
        self::assertSame(1, $page['page']);
        self::assertSame(1, $page['pageSize']);
        self::assertSame(2, $page['pageCount']);
        self::assertSame((int) $first['id'], (int) $page['items'][0]['id']);
        self::assertGreaterThanOrEqual(2, $page['counts']['mine']);
        self::assertGreaterThanOrEqual(3, $page['counts']['all']);
    }

    public function testRegistryPaginationDeduplicatesMultipleCurrentAssignments(): void
    {
        $initiator = $this->createUser('dev.it.registry-duplicate', 'Инициатор дубля назначения');
        $firstExecutor = $this->createUser('dev.it.registry-executor1', 'Первый исполнитель');
        $latestExecutor = $this->createUser('dev.it.registry-executor2', 'Последний исполнитель');
        $request = $this->createRegisteredRequest($initiator, 'маркер дубля назначения');
        $requestId = (int) $request['id'];

        foreach ([$firstExecutor, $latestExecutor] as $executorId) {
            $this->db()->createCommand()->insert('{{%request_assignments}}', [
                'request_id' => $requestId,
                'assignment_type' => 'executor',
                'user_id' => $executorId,
                'assigned_by' => $initiator,
                'valid_from' => Clock::now(),
            ])->execute();
        }

        $page = (new RequestQuery($this->db()))->findPage(
            $initiator,
            1,
            1,
            'all',
            null,
            'маркер дубля назначения',
            'desc',
        );

        self::assertSame(1, $page['total']);
        self::assertCount(1, $page['items']);
        self::assertSame($requestId, (int) $page['items'][0]['id']);
        self::assertSame($latestExecutor, (int) $page['items'][0]['executor_id']);
    }

    /** @return list<array<string, mixed>> */
    private function findAll(int $actorId): array
    {
        return (new RequestQuery($this->db()))->findPage($actorId, 1, 500, 'all', null, '', 'desc')['items'];
    }

    public function testCommentsQueryKeepsCursorPaginationOrderAndShape(): void
    {
        $initiator = $this->createUser('dev.it.comments.initiator', 'Автор заявки');
        $author = $this->createUser('dev.it.comments.author', 'Автор комментария');
        $requestId = (int) $this->createRegisteredRequest($initiator, 'comments-query')['id'];

        for ($index = 1; $index <= 52; ++$index) {
            $this->db()->createCommand()->insert('{{%request_comments}}', [
                'request_id' => $requestId,
                'author_id' => $author,
                'body' => "Комментарий {$index}",
                'created_at' => Clock::now(),
            ])->execute();
        }

        $query = new RequestQuery($this->db());
        $firstPage = $query->findCommentsPage($requestId, $initiator, null);

        self::assertSame(['items', 'hasMore', 'nextBeforeId'], array_keys($firstPage));
        self::assertCount(50, $firstPage['items']);
        self::assertTrue($firstPage['hasMore']);
        self::assertSame('Комментарий 3', $firstPage['items'][0]['body']);
        self::assertSame('Комментарий 52', $firstPage['items'][49]['body']);
        self::assertSame((int) $firstPage['items'][0]['id'], $firstPage['nextBeforeId']);
        self::assertSame(
            ['id', 'body', 'createdAt', 'authorName'],
            array_keys($firstPage['items'][0]),
        );

        $secondPage = $query->findCommentsPage($requestId, $initiator, $firstPage['nextBeforeId']);
        self::assertSame(['Комментарий 1', 'Комментарий 2'], array_column($secondPage['items'], 'body'));
        self::assertFalse($secondPage['hasMore']);
    }

    public function testCommentsQueryHidesRequestFromInactiveViewer(): void
    {
        $initiator = $this->createUser('dev.it.comments.owner', 'Автор заявки');
        $inactiveViewer = $this->createUser(
            'dev.it.comments.inactive',
            'Неактивный пользователь',
            isActive: false,
        );
        $requestId = (int) $this->createRegisteredRequest($initiator, 'comments-inactive')['id'];

        $this->expectException(RequestNotFound::class);
        (new RequestQuery($this->db()))->findCommentsPage($requestId, $inactiveViewer, null);
    }

    public function testUserQueriesReturnOnlyActiveUsersWithExpectedRoleAndSortOrder(): void
    {
        $executorB = $this->createUser('dev.it.lookup.executor-b', 'Яков Исполнитель');
        $executorA = $this->createUser('dev.it.lookup.executor-a', 'Анна Исполнитель');
        $inactiveExecutor = $this->createUser(
            'dev.it.lookup.executor-inactive',
            'Борис Исполнитель',
            isActive: false,
        );
        $expert = $this->createUser('dev.it.lookup.expert', 'Вера Эксперт');
        $unrelated = $this->createUser('dev.it.lookup.unrelated', 'Григорий Сотрудник');
        $this->grantRole($executorB, 'ic_executor');
        $this->grantRole($executorA, 'ic_executor');
        $this->grantRole($inactiveExecutor, 'ic_executor');
        $this->grantRole($expert, 'expert');
        $this->grantRole($unrelated, 'employee');

        $query = new RequestQuery($this->db());

        $executors = $query->findActiveExecutors();
        self::assertContains(['id' => $executorA, 'displayName' => 'Анна Исполнитель'], $executors);
        self::assertContains(['id' => $executorB, 'displayName' => 'Яков Исполнитель'], $executors);
        self::assertNotContains(['id' => $inactiveExecutor, 'displayName' => 'Борис Исполнитель'], $executors);
        self::assertNotContains(['id' => $unrelated, 'displayName' => 'Григорий Сотрудник'], $executors);
        self::assertSame(
            array_column($executors, 'id'),
            array_values(array_unique(array_column($executors, 'id'))),
        );
        $executorNames = array_column($executors, 'displayName');
        $sortedExecutorNames = $executorNames;
        sort($sortedExecutorNames, SORT_STRING);
        self::assertSame($sortedExecutorNames, $executorNames);

        $experts = $query->findActiveExperts();
        self::assertContains(['id' => $expert, 'displayName' => 'Вера Эксперт'], $experts);
        self::assertNotContains(['id' => $unrelated, 'displayName' => 'Григорий Сотрудник'], $experts);
        self::assertSame(
            array_column($experts, 'id'),
            array_values(array_unique(array_column($experts, 'id'))),
        );
    }

    public function testRegistryShowsOnlyTheMostRecentComment(): void
    {
        $initiator = $this->createUser('dev.it.initiator-lastcomment', 'Инициатор последнего комментария');
        $manager = $this->createUser('dev.it.manager-lastcomment', 'Руководитель просмотра комментария');
        $this->grantRole($manager, 'ic_manager');
        $request = $this->createRegisteredRequest($initiator, 'last-comment');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        self::assertNull(self::findRow($this->findAll($manager), $requestId)['last_comment_author']);

        $repository->addComment($requestId, $initiator, 'Первый комментарий');
        $repository->addComment($requestId, $manager, 'Второй, самый свежий комментарий');

        $row = self::findRow($this->findAll($manager), $requestId);
        self::assertSame('Руководитель просмотра комментария', $row['last_comment_author']);
        self::assertSame('Второй, самый свежий комментарий', $row['last_comment_body']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            (string) $row['last_comment_created_at'],
        );
    }

    public function testRegistryTruncatesAnOverlongLastCommentWithEllipsis(): void
    {
        // Реестр отдаёт предпросмотр, а не весь комментарий (до 10000
        // символов, COM-001) — иначе список раздувается без пользы (Qodo).
        $initiator = $this->createUser('dev.it.initiator-longcomment', 'Инициатор длинного комментария');
        $request = $this->createRegisteredRequest($initiator, 'long-comment');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $repository->addComment($requestId, $initiator, str_repeat('а', 600));

        $row = self::findRow($this->findAll($initiator), $requestId);
        self::assertSame(str_repeat('а', 500) . '…', $row['last_comment_body']);
    }

    public function testHasReportFlagFollowsDoc003Visibility(): void
    {
        // DOC-003: до завершения заявки отчёт виден только назначенному
        // исполнителю/эксперту и руководителю ИЦ/лаборатории; после
        // завершения — всем. Индикатор в реестре (issue #134) обязан
        // повторять то же правило, а не просто факт наличия документа.
        $initiator = $this->createUser('dev.it.initiator-hasreport', 'Инициатор отчёта');
        $executor = $this->createUser('dev.it.executor-hasreport', 'Исполнитель отчёта');
        $manager = $this->createUser('dev.it.manager-hasreport', 'Руководитель отчёта');
        $this->grantRole($manager, 'ic_manager');
        $labManager = $this->createUser('dev.it.labmanager-hasreport', 'Руководитель лаборатории отчёта');
        $this->grantRole($labManager, 'laboratory_manager');
        $expert = $this->createUser('dev.it.expert-hasreport', 'Эксперт отчёта');
        $outsider = $this->createUser('dev.it.outsider-hasreport', 'Посторонний сотрудник отчёта');
        $request = $this->createRegisteredRequest($initiator, 'has-report-flag');
        $requestId = (int) $request['id'];
        $now = Clock::now();

        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'executor',
            'user_id' => $executor,
            'assigned_by' => $initiator,
            'valid_from' => $now,
        ])->execute();
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'expert',
            'user_id' => $expert,
            'assigned_by' => $expert,
            'valid_from' => $now,
        ])->execute();
        $this->db()->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId,
            'document_type' => 'report',
            'title' => 'Отчёт испытаний',
            'created_by' => $executor,
            'created_at' => $now,
        ])->execute();
        $documentId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $documentId,
            'version' => 1,
            'storage_key' => str_repeat('a', 64),
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'sha256' => str_repeat('0', 64),
            'uploaded_by' => $executor,
            'created_at' => $now,
        ])->execute();

        $repository = new RequestRepository($this->db());
        $executorRow = self::findRow($this->findAll($executor), $requestId);
        self::assertSame(1, (int) $executorRow['has_report']);
        self::assertSame('report.pdf', $executorRow['report_original_name']);
        self::assertNotNull($executorRow['report_version_id']);

        $managerRow = self::findRow($this->findAll($manager), $requestId);
        self::assertSame(1, (int) $managerRow['has_report']);
        self::assertSame('report.pdf', $managerRow['report_original_name']);

        $labManagerRow = self::findRow($this->findAll($labManager), $requestId);
        self::assertSame(1, (int) $labManagerRow['has_report']);
        self::assertSame('report.pdf', $labManagerRow['report_original_name']);

        $expertRow = self::findRow($this->findAll($expert), $requestId);
        self::assertSame(1, (int) $expertRow['has_report']);
        self::assertSame('report.pdf', $expertRow['report_original_name']);

        $outsiderRow = self::findRow($this->findAll($outsider), $requestId);
        self::assertSame(0, (int) $outsiderRow['has_report']);
        self::assertNull($outsiderRow['report_version_id']);
        self::assertNull($outsiderRow['report_original_name']);

        $this->db()->createCommand()->update(
            '{{%requests}}',
            ['status' => 'completed'],
            ['id' => $requestId],
        )->execute();
        $outsiderAfterCompletion = self::findRow($this->findAll($outsider), $requestId);
        self::assertSame(1, (int) $outsiderAfterCompletion['has_report']);
        self::assertSame('report.pdf', $outsiderAfterCompletion['report_original_name']);
    }

    public function testHasReportFlagIgnoresASoftDeletedVersion(): void
    {
        // Карточка (findDetails) считает отчёт действующим только при
        // наличии неудалённой версии (v.deleted_at IS NULL) — индикатор в
        // реестре обязан следовать той же семантике, а не одному факту
        // существования строки request_documents (Qodo).
        $initiator = $this->createUser('dev.it.initiator-deletedversion', 'Инициатор удалённой версии');
        $executor = $this->createUser('dev.it.executor-deletedversion', 'Исполнитель удалённой версии');
        $request = $this->createRegisteredRequest($initiator, 'deleted-report-version');
        $requestId = (int) $request['id'];
        $now = Clock::now();

        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'executor',
            'user_id' => $executor,
            'assigned_by' => $initiator,
            'valid_from' => $now,
        ])->execute();
        $this->db()->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId,
            'document_type' => 'report',
            'title' => 'Отчёт испытаний',
            'created_by' => $executor,
            'created_at' => $now,
        ])->execute();
        $documentId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $documentId,
            'version' => 1,
            'storage_key' => str_repeat('a', 64),
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'sha256' => str_repeat('0', 64),
            'uploaded_by' => $executor,
            'created_at' => $now,
            'deleted_at' => $now,
        ])->execute();

        $row = self::findRow(
            $this->findAll($executor),
            $requestId,
        );
        self::assertSame(0, (int) $row['has_report']);
        self::assertNull($row['report_version_id']);
        self::assertNull($row['report_original_name']);
    }

    public function testManagerWithTwoRolesIsNotifiedOnlyOnceOnCreate(): void
    {
        // WF-008/NTF-003: один и тот же пользователь с ролями ic_manager и
        // laboratory_manager должен получить ровно одно письмо о создании
        // заявки, а не по одному на каждую роль (регрессия, найденная Qodo
        // в PR #58).
        $dualRoleManager = $this->createUser(
            'dev.it.dualmanager',
            'Совмещённый руководитель',
            'dual.manager@example.invalid',
        );
        $this->grantRole($dualRoleManager, 'ic_manager');
        $this->grantRole($dualRoleManager, 'laboratory_manager');
        $initiator = $this->createUser('dev.it.initiator7', 'Тестовый инициатор 7');

        $request = $this->createRegisteredRequest($initiator, 'notify-dedup');

        $notifiedCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} "
            . "WHERE request_id = :id AND event_type = 'request.created' "
            . "AND recipient_email = 'dual.manager@example.invalid'",
            [':id' => $request['id']],
        );
        self::assertSame(1, (int) $notifiedCount);
    }

    public function testInitiatorIsNotifiedOfOwnRequestRegistration(): void
    {
        // REQ-009: инициатор получает отдельное подтверждающее письмо о
        // регистрации своей заявки, помимо уведомления руководителей.
        $initiator = $this->createUser(
            'dev.it.initiator8notify',
            'Тестовый инициатор для уведомления',
            'initiator-notify@example.invalid',
        );

        $request = $this->createRegisteredRequest($initiator, 'notify-initiator');

        $notificationCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} "
            . "WHERE request_id = :id AND event_type = 'request.created' "
            . "AND recipient_email = 'initiator-notify@example.invalid'",
            [':id' => $request['id']],
        );
        self::assertSame(1, (int) $notificationCount);

        $notification = $this->db()->createCommand(
            "SELECT subject, body FROM {{%notification_outbox}} "
            . "WHERE request_id = :id AND event_type = 'request.created' "
            . "AND recipient_email = 'initiator-notify@example.invalid' "
            . 'ORDER BY id DESC LIMIT 1',
            [':id' => $request['id']],
        )->queryOne();

        self::assertNotFalse($notification);
        self::assertStringContainsString('зарегистрирована', $notification['subject']);
        self::assertStringContainsString('зарегистрирована', $notification['body']);
    }

    public function testWhitespaceOnlyEmailIsTreatedAsMissingAndSurroundingSpacesAreTrimmed(): void
    {
        // LDAP-синхронизация не гарантирует, что email придёт без пробелов
        // по краям — ни пробельная строка не должна считаться валидным
        // адресом, ни сохранённый в notification_outbox адрес не должен
        // нести пробелы, иначе Mailer упадёт при построении Address.
        $whitespaceOnlyEmailManager = $this->createUser('dev.it.manager8', 'Руководитель без адреса', '   ');
        $this->grantRole($whitespaceOnlyEmailManager, 'ic_manager');
        $paddedEmailInitiator = $this->createUser(
            'dev.it.initiator9notify',
            'Инициатор с пробелами в адресе',
            '  padded@example.invalid  ',
        );

        $request = $this->createRegisteredRequest($paddedEmailInitiator, 'notify-trim');

        $blankRecipientCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} "
            . "WHERE request_id = :id AND TRIM(recipient_email) = ''",
            [':id' => $request['id']],
        );
        self::assertSame(
            0,
            (int) $blankRecipientCount,
            'Пробельный email не должен считаться валидным получателем',
        );

        $initiatorRecipient = $this->scalar(
            "SELECT recipient_email FROM {{%notification_outbox}} "
            . "WHERE request_id = :id AND event_type = 'request.created' "
            . "AND recipient_email LIKE '%padded%'",
            [':id' => $request['id']],
        );
        self::assertSame('padded@example.invalid', $initiatorRecipient);
    }

    public function testAssignedExpertSeesTheReportInDocumentsList(): void
    {
        // Issue #73: без этой видимости эксперт не может открыть отчёт,
        // на основании которого должен сформировать заключение.
        $initiator = $this->createUser('dev.it.initiator8', 'Инициатор');
        $executor = $this->createUser('dev.it.executor1', 'Исполнитель');
        $expert = $this->createUser('dev.it.expert2', 'Эксперт');
        $outsider = $this->createUser('dev.it.outsider2', 'Посторонний сотрудник');
        $request = $this->createRegisteredRequest($initiator, 'expert-report-visibility');
        $requestId = (int) $request['id'];
        $now = Clock::now();

        $this->db()->createCommand()->update(
            '{{%requests}}',
            ['status' => 'opinion_preparation'],
            ['id' => $requestId],
        )->execute();
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'executor',
            'user_id' => $executor,
            'assigned_by' => $initiator,
            'valid_from' => $now,
        ])->execute();
        $this->db()->createCommand()->insert('{{%request_assignments}}', [
            'request_id' => $requestId,
            'assignment_type' => 'expert',
            'user_id' => $expert,
            'assigned_by' => $expert,
            'valid_from' => $now,
        ])->execute();
        $this->db()->createCommand()->insert('{{%request_documents}}', [
            'request_id' => $requestId,
            'document_type' => 'report',
            'title' => 'Отчёт испытаний',
            'created_by' => $executor,
            'created_at' => $now,
        ])->execute();
        $documentId = (int) $this->db()->getLastInsertID();
        $this->db()->createCommand()->insert('{{%request_document_versions}}', [
            'document_id' => $documentId,
            'version' => 1,
            'storage_key' => str_repeat('a', 64),
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'sha256' => str_repeat('0', 64),
            'uploaded_by' => $executor,
            'created_at' => $now,
        ])->execute();

        $repository = new RequestRepository($this->db());

        $expertDetails = (new RequestQuery($this->db()))->findDetails($requestId, $expert);
        self::assertNotEmpty($expertDetails['documents']);
        self::assertSame('report', $expertDetails['documents'][0]['documentType']);

        $outsiderDetails = (new RequestQuery($this->db()))->findDetails($requestId, $outsider);
        self::assertEmpty($outsiderDetails['documents']);
    }

    public function testManagerCanReassignExecutorAfterWorkStarted(): void
    {
        // Issue #72: до этого изменения переназначение работало только в
        // registered — руководитель не мог исправить ошибочное назначение
        // после того, как исполнитель уже начал работу.
        $manager = $this->createUser('dev.it.manager4', 'Тестовый руководитель 4');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator9', 'Тестовый инициатор 9');
        $firstExecutor = $this->createUser('dev.it.executor2', 'Первый исполнитель');
        $this->grantRole($firstExecutor, 'ic_executor');
        $secondExecutor = $this->createUser('dev.it.executor3', 'Второй исполнитель');
        $this->grantRole($secondExecutor, 'ic_executor');
        $request = $this->createRegisteredRequest($initiator, 'reassign-in-progress');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $assigned = $repository->assignExecutor($requestId, $firstExecutor, (int) $request['lock_version'], $manager);
        $started = $repository->startRequest($requestId, (int) $assigned['lockVersion'], $manager);

        $reassigned = $repository->assignExecutor(
            $requestId,
            $secondExecutor,
            (int) $started['lockVersion'],
            $manager,
        );

        self::assertSame($secondExecutor, $reassigned['executorId']);
        self::assertSame((int) $started['lockVersion'] + 1, $reassigned['lockVersion']);
        self::assertSame(
            'in_progress',
            $this->scalar('SELECT status FROM {{%requests}} WHERE id = :id', [':id' => $requestId]),
        );

        $activeAssignments = $this->scalar(
            "SELECT COUNT(*) FROM {{%request_assignments}} WHERE request_id = :id "
            . "AND assignment_type = 'executor' AND valid_to IS NULL",
            [':id' => $requestId],
        );
        self::assertSame(1, (int) $activeAssignments);

        $closedForFirstExecutor = $this->scalar(
            "SELECT COUNT(*) FROM {{%request_assignments}} WHERE request_id = :id "
            . "AND assignment_type = 'executor' AND user_id = :executor AND valid_to IS NOT NULL",
            [':id' => $requestId, ':executor' => $firstExecutor],
        );
        self::assertSame(1, (int) $closedForFirstExecutor);
    }

    public function testReassigningCurrentExecutorIsRejected(): void
    {
        // WF-013: назначение того же исполнителя — no-op, который бесполезно
        // плодит запись истории, увеличивает lock_version и шлёт письмо.
        $manager = $this->createUser('dev.it.manager6', 'Тестовый руководитель 6');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator11', 'Тестовый инициатор 11');
        $executor = $this->createUser('dev.it.executor5', 'Исполнитель');
        $this->grantRole($executor, 'ic_executor');
        $request = $this->createRegisteredRequest($initiator, 'reassign-noop');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $assigned = $repository->assignExecutor($requestId, $executor, (int) $request['lock_version'], $manager);

        try {
            $repository->assignExecutor($requestId, $executor, (int) $assigned['lockVersion'], $manager);
            self::fail('Повторное назначение того же исполнителя должно быть отклонено');
        } catch (AssignmentDenied $error) {
            self::assertSame('WF-013', $error->ruleId);
        }

        self::assertSame(
            (int) $assigned['lockVersion'],
            (int) $this->scalar('SELECT lock_version FROM {{%requests}} WHERE id = :id', [':id' => $requestId]),
        );
        $activeAssignments = $this->scalar(
            "SELECT COUNT(*) FROM {{%request_assignments}} WHERE request_id = :id "
            . "AND assignment_type = 'executor' AND valid_to IS NULL",
            [':id' => $requestId],
        );
        self::assertSame(1, (int) $activeAssignments);
    }

    public function testReassignmentEmailReflectsInProgressStatus(): void
    {
        // Issue #72/WF-012: переназначенному исполнителю не нужно «принимать
        // в работу» заявку, по которой уже начались испытания, — письмо должно
        // отражать реальный статус, а не всегда говорить про первичное назначение.
        $manager = $this->createUser('dev.it.manager7', 'Тестовый руководитель 7');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator12', 'Тестовый инициатор 12');
        $firstExecutor = $this->createUser('dev.it.executor6', 'Первый исполнитель', 'first.executor@example.invalid');
        $this->grantRole($firstExecutor, 'ic_executor');
        $secondExecutor = $this->createUser(
            'dev.it.executor7',
            'Второй исполнитель',
            'second.executor@example.invalid',
        );
        $this->grantRole($secondExecutor, 'ic_executor');
        $request = $this->createRegisteredRequest($initiator, 'reassign-email-text');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $assigned = $repository->assignExecutor($requestId, $firstExecutor, (int) $request['lock_version'], $manager);
        $started = $repository->startRequest($requestId, (int) $assigned['lockVersion'], $manager);
        $repository->assignExecutor($requestId, $secondExecutor, (int) $started['lockVersion'], $manager);

        $body = $this->scalar(
            "SELECT body FROM {{%notification_outbox}} WHERE request_id = :id "
            . "AND recipient_email = 'second.executor@example.invalid'",
            [':id' => $requestId],
        );
        self::assertIsString($body);
        self::assertStringContainsString('уже начались испытания', $body);
        self::assertStringNotContainsString('принять её в работу', $body);
    }

    public function testCannotAssignExecutorAfterOpinionPreparationStarted(): void
    {
        $manager = $this->createUser('dev.it.manager5', 'Тестовый руководитель 5');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator10', 'Тестовый инициатор 10');
        $executor = $this->createUser('dev.it.executor4', 'Исполнитель');
        $this->grantRole($executor, 'ic_executor');
        $request = $this->createRegisteredRequest($initiator, 'reassign-too-late');
        $requestId = (int) $request['id'];

        $this->db()->createCommand()->update(
            '{{%requests}}',
            ['status' => 'opinion_preparation'],
            ['id' => $requestId],
        )->execute();

        $repository = new RequestRepository($this->db());
        $this->expectException(ConcurrentRequestModification::class);
        $repository->assignExecutor($requestId, $executor, (int) $request['lock_version'], $manager);
    }

    public function testHistoryIncludesTheTargetNameForAssignmentEvents(): void
    {
        // Issue #117: лента должна называть, КОГО назначили, а не только
        // кто выполнил действие (actorName) — targetName резолвится через
        // request_assignments по assignment_id из payload_json аудита.
        $manager = $this->createUser('dev.it.manager6', 'Тестовый руководитель 6');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator11', 'Тестовый инициатор 11');
        $executor = $this->createUser('dev.it.executor5', 'Целевой Исполнитель');
        $this->grantRole($executor, 'ic_executor');
        $firstExpert = $this->createUser('dev.it.expert3', 'Первый Эксперт');
        $this->grantRole($firstExpert, 'expert');
        $secondExpert = $this->createUser('dev.it.expert4', 'Второй Эксперт');
        $this->grantRole($secondExpert, 'expert');
        $request = $this->createRegisteredRequest($initiator, 'history-target-name');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $assigned = $repository->assignExecutor($requestId, $executor, (int) $request['lock_version'], $manager);

        $this->db()->createCommand()->update(
            '{{%requests}}',
            ['status' => 'opinion_preparation'],
            ['id' => $requestId],
        )->execute();
        $claimed = $repository->claimExpert($requestId, (int) $assigned['lockVersion'], $firstExpert);
        $repository->reassignExpert($requestId, $secondExpert, (int) $claimed['lockVersion'], $firstExpert);

        $history = (new RequestQuery($this->db()))->findDetails($requestId, $manager)['history'];
        $byAction = [];
        foreach ($history as $row) {
            $byAction[$row['action']] = $row;
        }

        self::assertSame('Целевой Исполнитель', $byAction['assign_executor']['targetName']);
        self::assertSame('Второй Эксперт', $byAction['reassign_expert']['targetName']);
    }

    public function testHistoryResolvesTheTargetNameForLegacyDoubleEncodedPayload(): void
    {
        // Issue про двойное кодирование payload_json (PR #121): записи,
        // сделанные до фикса и до backfill-миграции m260730_000001,
        // хранят JSON-строку внутри JSON-строки (JSON_TYPE = 'STRING').
        // findDetails() обязан резолвить targetName и для них тоже —
        // имитируем такую запись через JSON_QUOTE поверх уже
        // корректно сохранённого payload_json.
        $manager = $this->createUser('dev.it.manager7', 'Тестовый руководитель 7');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator12', 'Тестовый инициатор 12');
        $executor = $this->createUser('dev.it.executor6', 'Легаси Исполнитель');
        $this->grantRole($executor, 'ic_executor');
        $request = $this->createRegisteredRequest($initiator, 'legacy-payload-json');
        $requestId = (int) $request['id'];

        $repository = new RequestRepository($this->db());
        $repository->assignExecutor($requestId, $executor, (int) $request['lock_version'], $manager);

        $this->db()->createCommand(
            "UPDATE {{%audit_events}} SET payload_json = JSON_QUOTE(payload_json) "
            . "WHERE entity_type = 'request' AND entity_id = :id AND event_type = 'request.executor_assigned'",
            [':id' => $requestId],
        )->execute();
        self::assertSame(
            'STRING',
            $this->scalar(
                "SELECT JSON_TYPE(payload_json) FROM {{%audit_events}} "
                . "WHERE entity_type = 'request' AND entity_id = :id AND event_type = 'request.executor_assigned'",
                [':id' => $requestId],
            ),
        );

        $history = (new RequestQuery($this->db()))->findDetails($requestId, $manager)['history'];
        $byAction = [];
        foreach ($history as $row) {
            $byAction[$row['action']] = $row;
        }

        self::assertSame('Легаси Исполнитель', $byAction['assign_executor']['targetName']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function findRow(array $rows, int $requestId): array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $requestId) {
                return $row;
            }
        }
        self::fail("Row for request {$requestId} not found in registry listing.");
    }
}

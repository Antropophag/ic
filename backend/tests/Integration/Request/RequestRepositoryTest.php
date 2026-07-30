<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\AssignmentDenied;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RejectDenied;
use App\Domain\Request\WithdrawDenied;
use App\Infrastructure\Clock;
use App\Infrastructure\Request\RequestRepository;
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

    public function testRejectFailsOnStaleLockVersion(): void
    {
        $manager = $this->createUser('dev.it.manager1', 'Тестовый руководитель 1');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator1', 'Тестовый инициатор 1');
        $request = $this->createRegisteredRequest($initiator, 'stale-reject');

        $repository = new RequestRepository($this->db());
        $this->expectException(ConcurrentRequestModification::class);
        $repository->rejectRequest((int) $request['id'], (int) $request['lock_version'] + 1, $manager);
    }

    public function testRejectByManagerTransitionsStatusAndWritesAudit(): void
    {
        $manager = $this->createUser('dev.it.manager2', 'Тестовый руководитель 2');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator2', 'Тестовый инициатор 2');
        $request = $this->createRegisteredRequest($initiator, 'reject-audit');

        $repository = new RequestRepository($this->db());
        $result = $repository->rejectRequest((int) $request['id'], (int) $request['lock_version'], $manager);

        self::assertSame('rejected', $result['status']);
        self::assertSame((int) $request['lock_version'] + 1, $result['lockVersion']);

        $auditCount = $this->scalar(
            "SELECT COUNT(*) FROM {{%audit_events}} WHERE event_type = 'request.rejected' AND entity_id = :id",
            [':id' => $request['id']],
        );
        self::assertSame(1, (int) $auditCount);
    }

    public function testOnlyManagerCanReject(): void
    {
        $employee = $this->createUser('dev.it.employee1', 'Обычный сотрудник');
        $initiator = $this->createUser('dev.it.initiator3', 'Тестовый инициатор 3');
        $request = $this->createRegisteredRequest($initiator, 'reject-denied');

        $repository = new RequestRepository($this->db());
        $this->expectException(RejectDenied::class);
        $repository->rejectRequest((int) $request['id'], (int) $request['lock_version'], $employee);
    }

    public function testOnlyInitiatorCanWithdraw(): void
    {
        $other = $this->createUser('dev.it.other1', 'Другой сотрудник');
        $initiator = $this->createUser('dev.it.initiator4', 'Тестовый инициатор 4');
        $request = $this->createRegisteredRequest($initiator, 'withdraw-denied');

        $repository = new RequestRepository($this->db());
        $this->expectException(WithdrawDenied::class);
        $repository->withdrawRequest((int) $request['id'], (int) $request['lock_version'], $other);
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
        $repository->withdrawRequest($requestId, (int) $request['lock_version'], $initiator);
    }

    public function testCanRejectAndCanWithdrawFlagsInRegistry(): void
    {
        $manager = $this->createUser('dev.it.manager3', 'Тестовый руководитель 3');
        $this->grantRole($manager, 'ic_manager');
        $initiator = $this->createUser('dev.it.initiator6', 'Тестовый инициатор 6');
        $request = $this->createRegisteredRequest($initiator, 'registry-flags');

        $repository = new RequestRepository($this->db());

        $managerRow = self::findRow($repository->findLatest($manager, 500), (int) $request['id']);
        self::assertSame(1, (int) $managerRow['can_reject']);
        self::assertSame(0, (int) $managerRow['can_withdraw']);

        $initiatorRow = self::findRow($repository->findLatest($initiator, 500), (int) $request['id']);
        self::assertSame(0, (int) $initiatorRow['can_reject']);
        self::assertSame(1, (int) $initiatorRow['can_withdraw']);
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
        self::assertStringContainsString('принята в работу', $notification['subject']);
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

        $managerNotified = $this->scalar(
            "SELECT COUNT(*) FROM {{%notification_outbox}} "
            . "WHERE request_id = :id AND recipient_email LIKE '%manager%'",
            [':id' => $request['id']],
        );
        self::assertSame(0, (int) $managerNotified, 'Пробельный email не должен считаться валидным получателем');

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

        $expertDetails = $repository->findDetails($requestId, $expert);
        self::assertNotEmpty($expertDetails['documents']);
        self::assertSame('report', $expertDetails['documents'][0]['documentType']);

        $outsiderDetails = $repository->findDetails($requestId, $outsider);
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
        // в работу» заявку, которая уже в работе — письмо должно отражать
        // реальный статус, а не всегда говорить про первичное назначение.
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
        self::assertStringContainsString('уже в работе', $body);
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

        $history = $repository->findDetails($requestId, $manager)['history'];
        $byAction = [];
        foreach ($history as $row) {
            $byAction[$row['action']] = $row;
        }

        self::assertSame('Целевой Исполнитель', $byAction['assign_executor']['targetName']);
        self::assertSame('Второй Эксперт', $byAction['reassign_expert']['targetName']);
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

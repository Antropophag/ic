<?php

declare(strict_types=1);

namespace Tests\Integration\Request;

use App\Application\Request\CreateRequestInput;
use App\Domain\Request\ConcurrentRequestModification;
use App\Domain\Request\RejectDenied;
use App\Domain\Request\WithdrawDenied;
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
        $now = gmdate('Y-m-d H:i:s.u');

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
